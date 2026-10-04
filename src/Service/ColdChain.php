<?php

namespace Base\Restaurant\Service;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Enum\PickupMode;
use Base\Marketplace\Service\Pickups;
use Base\Restaurant\Entity\Product\TakeHome;
use Base\Restaurant\Exception\RestaurantException;
use Base\Restaurant\Model\HandOver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * How fresh dishes may leave the restaurant. omnibase/marketplace hands an
 * order over (Entity\Order\Pickup) and offers every shipping method to every
 * basket; what may travel cold, and when, is decided here:
 *
 *   - collected at the restaurant, on a slot of a day it is open for it;
 *   - brought nearby by the restaurant itself, to the postcodes of
 *     restaurant.delivery.zip_codes, from a minimum basket;
 *   - a chilled parcel, only when every dish of the basket may travel
 *     (TakeHome::isParcel()), when the shortest use-by date outlasts the
 *     journey and leaves the days asked on arrival, and handed to the carrier
 *     from Monday to Wednesday (restaurant.parcel.ship_days): a parcel that
 *     left on a Thursday could spend the weekend in a depot. No carrier
 *     configured (Service\ParcelCarrier), no parcel.
 *
 * The dishes are made for the day asked: that day is the one they are made
 * on, and their use-by dates count from it.
 */
class ColdChain
{
    /**
     * @param list<string>                $zipCodes
     * @param array<string, mixed>        $parcel   enabled, ship_days (1 Monday … 7 Sunday), transit_days, margin_days, cutoff
     */
    public function __construct(
        private readonly Pickups $pickups,
        private readonly ?ParcelCarrier $carrier = null,
        #[Autowire('%restaurant.takehome.pickup%')] private readonly bool $pickup = true,
        #[Autowire('%restaurant.takehome.notice_days%')] private readonly int $noticeDays = 1,
        #[Autowire('%restaurant.delivery.zip_codes%')] private readonly array $zipCodes = [],
        #[Autowire('%restaurant.delivery.minimum%')] private readonly int $deliveryMinimum = 0,
        #[Autowire('%restaurant.parcel%')] private readonly array $parcel = [],
    ) {
    }

    /**
     * Every way, for this basket: offered or not and why, and its days.
     *
     * @param list<array{product: Product, quantity: int}> $lines
     *
     * @return array<string, HandOver> by mode ("pickup", "delivery", "shipping")
     */
    public function options(array $lines, ?string $postcode = null, ?\DateTimeImmutable $now = null): array
    {
        $now ??= $this->pickups->now();
        $options = [];
        foreach (PickupMode::cases() as $mode) {
            $days = PickupMode::SHIPPING === $mode ? $this->shippingDays($now) : $this->days($now);
            try {
                $this->allows($mode, $lines, $postcode, $now, PickupMode::DELIVERY === $mode && null === $postcode);
                $options[$mode->value] = new HandOver($mode, [] !== $days, $days, [] === $days ? 'takehome.error.no_day' : null);
            } catch (RestaurantException $e) {
                $options[$mode->value] = new HandOver($mode, false, [], $e->getMessage(), $e->parameters);
            }
        }

        return $options;
    }

    /**
     * This basket, this way, for this day - or why not.
     *
     * @param list<array{product: Product, quantity: int}> $lines
     *
     * @throws RestaurantException with a key of the "restaurant" translations (takehome.error.*)
     */
    public function check(PickupMode $mode, array $lines, \DateTimeImmutable $day, ?string $postcode = null, ?\DateTimeImmutable $now = null): void
    {
        $now ??= $this->pickups->now();
        $this->allows($mode, $lines, $postcode, $now);

        $day = $day->setTime(0, 0);
        if (PickupMode::SHIPPING === $mode) {
            if (!\in_array((int) $day->format('N'), $this->shipDays(), true)) {
                throw new RestaurantException('takehome.error.parcel_day');
            }
            if ($day < $this->earliestShipping($now)) {
                throw new RestaurantException('takehome.error.too_soon');
            }

            return;
        }
        if ($day < $this->earliest($now)) {
            throw new RestaurantException('takehome.error.too_soon');
        }
        if (!$this->pickups->slots($day, $now)) {
            throw new RestaurantException('takehome.error.no_day');
        }
    }

    /** @return list<\DateTimeImmutable> the days an order may be collected or brought */
    public function days(?\DateTimeImmutable $now = null): array
    {
        $now ??= $this->pickups->now();
        $earliest = $this->earliest($now);

        return array_values(array_filter($this->pickups->days($now), fn (\DateTimeImmutable $day) => $day >= $earliest));
    }

    /** @return list<\DateTimeImmutable> the next days a parcel may be handed to the carrier (four weeks ahead) */
    public function shippingDays(?\DateTimeImmutable $now = null): array
    {
        $now ??= $this->pickups->now();
        $days = [];
        for ($day = $this->earliestShipping($now), $i = 0; $i < 28 && \count($days) < 6; ++$i, $day = $day->modify('+1 day')) {
            if (\in_array((int) $day->format('N'), $this->shipDays(), true)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /** The day a parcel that leaves on $shipped is delivered. */
    public function arrival(\DateTimeImmutable $shipped): \DateTimeImmutable
    {
        return $shipped->setTime(0, 0)->modify(sprintf('+%d days', $this->transitDays()));
    }

    /**
     * The use-by date of a basket made on this day: its shortest.
     *
     * @param list<array{product: Product, quantity: int}> $lines
     */
    public function useBy(array $lines, \DateTimeImmutable $madeOn): ?\DateTimeImmutable
    {
        $dates = [];
        foreach ($lines as $line) {
            if ($line['product'] instanceof TakeHome) {
                $dates[] = $line['product']->useBy($madeOn);
            }
        }

        return $dates ? min($dates) : null;
    }

    /**
     * Those of these lines that may go in a chilled parcel: the dish travels, and keeps long enough.
     *
     * @param list<array{product: Product, quantity: int}> $lines
     *
     * @return list<array{product: Product, quantity: int}>
     */
    public function travelling(array $lines): array
    {
        $needed = $this->transitDays() + (int) ($this->parcel['margin_days'] ?? 2);

        return array_values(array_filter($lines, fn (array $line) => $line['product'] instanceof TakeHome && $line['product']->isParcel() && $line['product']->getShelfLife() >= $needed));
    }

    public function deliversTo(?string $postcode): bool
    {
        return $this->pickups->deliversTo($postcode, $this->zipCodes);
    }

    /**
     * @param list<array{product: Product, quantity: int}> $lines
     *
     * @throws RestaurantException
     */
    private function allows(PickupMode $mode, array $lines, ?string $postcode, \DateTimeImmutable $now, bool $anyPostcode = false): void
    {
        if (!$lines) {
            throw new RestaurantException('takehome.error.empty');
        }
        match ($mode) {
            PickupMode::PICKUP => $this->pickup ?: throw new RestaurantException('takehome.error.mode'),
            PickupMode::DELIVERY => $this->allowsDelivery($lines, $postcode, $anyPostcode),
            PickupMode::SHIPPING => $this->allowsParcel($lines),
        };
    }

    /** @param list<array{product: Product, quantity: int}> $lines */
    private function allowsDelivery(array $lines, ?string $postcode, bool $anyPostcode): void
    {
        if (!$this->zipCodes) {
            throw new RestaurantException('takehome.error.mode');
        }
        if (!$anyPostcode && !$this->deliversTo($postcode)) {
            throw new RestaurantException('takehome.error.out_of_zone', ['postcode' => (string) $postcode]);
        }
        $total = 0;
        foreach ($lines as $line) {
            $total += (int) $line['product']->getUnitPrice() * (int) $line['quantity'];
        }
        if ($total < $this->deliveryMinimum) {
            throw new RestaurantException('takehome.error.delivery_minimum', ['minimum' => $this->deliveryMinimum / 100]);
        }
    }

    /** @param list<array{product: Product, quantity: int}> $lines */
    private function allowsParcel(array $lines): void
    {
        if (!($this->parcel['enabled'] ?? true) || !$this->carrier?->isConfigured()) {
            throw new RestaurantException('takehome.error.mode');
        }
        $shortest = null;
        foreach ($lines as $line) {
            $product = $line['product'];
            if (!$product instanceof TakeHome || !$product->isParcel()) {
                throw new RestaurantException('takehome.error.parcel_dish', ['dish' => (string) $product->getTitle()]);
            }
            $shortest = null === $shortest ? $product->getShelfLife() : min($shortest, $product->getShelfLife());
        }
        // Made the day it leaves, it must still keep `margin_days` when it arrives.
        if ($shortest < $this->transitDays() + (int) ($this->parcel['margin_days'] ?? 2)) {
            throw new RestaurantException('takehome.error.parcel_shelf_life');
        }
    }

    private function earliest(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTime(0, 0)->modify(sprintf('+%d days', max(0, $this->noticeDays)));
    }

    /** The first day a parcel may be asked for: the notice, and tomorrow at the earliest once the cut-off hour is past. */
    private function earliestShipping(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $earliest = $this->earliest($now);
        if (0 === $this->noticeDays && $now->format('H:i') >= (string) ($this->parcel['cutoff'] ?? '12:00')) {
            $earliest = $earliest->modify('+1 day');
        }

        return $earliest;
    }

    /** @return list<int> ISO weekdays: 1 Monday … 7 Sunday */
    private function shipDays(): array
    {
        return array_map('intval', (array) ($this->parcel['ship_days'] ?? [1, 2, 3]));
    }

    private function transitDays(): int
    {
        return max(1, (int) ($this->parcel['transit_days'] ?? 1));
    }
}
