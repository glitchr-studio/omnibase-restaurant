<?php

namespace Base\Restaurant\Service;

use Base\Entity\User;
use Base\Marketplace\Entity\Order\Pickup;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Enum\PickupMode;
use Base\Marketplace\Model\QuickPayment;
use Base\Marketplace\Service\CartException;
use Base\Marketplace\Service\Pickups;
use Base\Marketplace\Service\QuickOrder;
use Base\Restaurant\Exception\RestaurantException;
use Base\Restaurant\Model\TakeHomeOrder;
use Base\Restaurant\Repository\TakeHomeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * An order of the take-home shop, without an account: the dishes, an e-mail
 * address, how it leaves. The cold chain says whether it may leave that way
 * on that day (Service\ColdChain); omnibase/marketplace's quick order makes
 * the order for the address and takes the payment (QuickOrder::buy()); its
 * hand-over is written (Pickup: the mode, the day and slot, who, where) and
 * the customer follows it on /suivi/{token}. Paid, it is a ticket on the
 * pass (Service\TakeawayTickets).
 *
 * A delivery or a parcel is charged as a line of the order - a product of
 * the shop named by restaurant.delivery.fee_product / restaurant.parcel.fee_product:
 * a quick order has no shipping charge of its own.
 */
class TakeHomeOrders
{
    public function __construct(
        private readonly QuickOrder $quickOrder,
        private readonly ColdChain $coldChain,
        private readonly Pickups $pickups,
        private readonly TakeHomeRepository $dishes,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%restaurant.takehome.max_quantity%')] private readonly int $maxQuantity = 20,
        #[Autowire('%restaurant.delivery.fee_product%')] private readonly ?string $deliveryFee = null,
        #[Autowire('%restaurant.parcel.fee_product%')] private readonly ?string $parcelFee = null,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->quickOrder->isEnabled();
    }

    /** @return list<string> every slot of a day ("11:00-11:30", …): the form's list, checked against the day chosen when the order comes */
    public function slots(): array
    {
        $far = $this->pickups->now()->modify('+2 days');

        return $this->pickups->slots($far, $far->setTime(0, 0)->modify('-1 day'));
    }

    /** The product charged for bringing or sending an order, when the shop names one. */
    public function fee(PickupMode $mode): ?Product
    {
        $slug = match ($mode) {
            PickupMode::DELIVERY => $this->deliveryFee,
            PickupMode::SHIPPING => $this->parcelFee,
            default => null,
        };
        $product = $slug ? $this->entityManager->getRepository(Product::class)->findOneBy(['slug' => $slug]) : null;

        return $product instanceof Product && $product->isForSell() ? $product : null;
    }

    /**
     * @return array{payment: QuickPayment, pickup: Pickup}
     *
     * @throws RestaurantException with a key of the "restaurant" translations (takehome.error.*), or "@marketplace.…"
     */
    public function place(TakeHomeOrder $request, ?User $signedIn = null): array
    {
        $lines = [];
        foreach ($request->quantities() as $id => $quantity) {
            $dish = $this->dishes->find($id);
            if (!$dish || !$dish->isAvailable()) {
                throw new RestaurantException('takehome.error.unavailable');
            }
            if ($quantity > $this->maxQuantity || (null !== $dish->getStock() && $quantity > $dish->getStock())) {
                throw new RestaurantException('takehome.error.quantity', ['dish' => (string) $dish->getTitle()]);
            }
            $lines[] = ['product' => $dish, 'quantity' => $quantity];
        }
        if (!$lines) {
            throw new RestaurantException('takehome.error.empty');
        }

        $mode = PickupMode::from($request->mode);
        $now = $this->pickups->now();
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->day, $now->getTimezone()) ?: throw new RestaurantException('takehome.error.no_day');
        $this->coldChain->check($mode, $lines, $day, $request->postcode, $now);

        $slot = null;
        if (PickupMode::SHIPPING !== $mode) {
            $slot = $request->slot;
            if (!$slot || !\in_array($slot, $this->pickups->slots($day, $now), true)) {
                throw new RestaurantException('takehome.error.slot');
            }
        }
        if ($mode->needsAddress() && ('' === trim((string) $request->address) || '' === trim((string) $request->postcode) || '' === trim((string) $request->city))) {
            throw new RestaurantException('takehome.error.address');
        }
        if ($fee = $this->fee($mode)) {
            $lines[] = ['product' => $fee, 'quantity' => 1];
        }

        try {
            $payment = $this->quickOrder->buy($lines, mb_strtolower(trim($request->email)), $signedIn);
        } catch (CartException $e) {
            throw new RestaurantException('@marketplace.'.$e->getMessage(), $e->getParameters(), $e);
        }

        // Written once the order exists; paid at once (a payment taken on the
        // spot), it is received already and on the pass.
        $pickup = $this->pickups->open($payment->order, $mode, $day, [
            'contactName' => $request->name,
            'email' => mb_strtolower(trim($request->email)),
            'phone' => $request->phone,
            'slot' => $slot,
            'address' => $request->address,
            'postcode' => $request->postcode,
            'city' => $request->city,
            'country' => 'FR',
            'note' => $request->note,
        ], false);

        return ['payment' => $payment, 'pickup' => $pickup];
    }
}
