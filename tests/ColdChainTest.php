<?php

namespace Base\Restaurant\Tests;

use Base\Marketplace\Enum\PickupMode;
use Base\Marketplace\Repository\Order\PickupRepository;
use Base\Marketplace\Service\Pickups;
use Base\Restaurant\Entity\Product\TakeHome;
use Base\Restaurant\Exception\RestaurantException;
use Base\Restaurant\Service\ColdChain;
use Base\Restaurant\Service\ParcelCarrier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** How fresh dishes may leave: collected, brought nearby, or in a chilled parcel - Monday to Wednesday, when everything travels and keeps. */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class ColdChainTest extends TestCase
{
    private const ZONE = 'Europe/Paris';

    private function chain(bool $carrier = true, array $zips = ['67000', '674*'], int $notice = 1, int $minimum = 0, array $parcel = []): ColdChain
    {
        $pickups = new Pickups($this->createStub(EntityManagerInterface::class), $this->createStub(PickupRepository::class), new EventDispatcher(), $this->createStub(UrlGeneratorInterface::class), ['pickup', 'delivery', 'shipping'], [], ['from' => '11:00', 'to' => '19:00', 'step' => 30], 60, 14, self::ZONE);
        $parcelCarrier = $this->createStub(ParcelCarrier::class);
        $parcelCarrier->method('isConfigured')->willReturn($carrier);

        return new ColdChain($pickups, $parcelCarrier, true, $notice, $zips, $minimum, $parcel + ['enabled' => true, 'ship_days' => [1, 2, 3], 'transit_days' => 1, 'margin_days' => 2, 'cutoff' => '12:00']);
    }

    private function dish(string $title, int $shelfLife = 5, bool $parcel = true, int $price = 900): TakeHome
    {
        $dish = $this->getMockBuilder(TakeHome::class)->disableOriginalConstructor()->onlyMethods(['getTitle', 'getUnitPrice'])->getMock();
        $dish->method('getTitle')->willReturn($title);
        $dish->method('getUnitPrice')->willReturn($price);
        $dish->setShelfLife($shelfLife)->setParcel($parcel);

        return $dish;
    }

    private function basket(TakeHome ...$dishes): array
    {
        return array_map(fn (TakeHome $dish) => ['product' => $dish, 'quantity' => 2], $dishes);
    }

    private function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone(self::ZONE));
    }

    private function refusal(callable $do): ?string
    {
        try {
            $do();
        } catch (RestaurantException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function testAFreshDishCollectedTomorrow(): void
    {
        $chain = $this->chain();
        $now = $this->at('2026-10-05 15:00'); // a Monday
        $basket = $this->basket($this->dish('Gyoza'));

        $chain->check(PickupMode::PICKUP, $basket, $this->at('2026-10-06'), null, $now);
        self::assertSame('takehome.error.too_soon', $this->refusal(fn () => $chain->check(PickupMode::PICKUP, $basket, $this->at('2026-10-05'), null, $now)), 'the kitchen needs a day');
        self::assertSame('2026-10-06', $chain->days($now)[0]->format('Y-m-d'));
        self::assertSame('takehome.error.empty', $this->refusal(fn () => $chain->check(PickupMode::PICKUP, [], $this->at('2026-10-06'), null, $now)));
    }

    public function testLocalDeliveryByPostcode(): void
    {
        $chain = $this->chain(minimum: 2000);
        $now = $this->at('2026-10-05 15:00');
        $day = $this->at('2026-10-07');

        $chain->check(PickupMode::DELIVERY, $this->basket($this->dish('Curry', price: 1200)), $day, '67000', $now);
        $chain->check(PickupMode::DELIVERY, $this->basket($this->dish('Curry', price: 1200)), $day, '67 400', $now);
        self::assertSame('takehome.error.out_of_zone', $this->refusal(fn () => $chain->check(PickupMode::DELIVERY, $this->basket($this->dish('Curry', price: 1200)), $day, '75011', $now)));
        self::assertSame('takehome.error.delivery_minimum', $this->refusal(fn () => $chain->check(PickupMode::DELIVERY, $this->basket($this->dish('Edamame', price: 400)), $day, '67000', $now)));
        self::assertSame('takehome.error.mode', $this->refusal(fn () => $this->chain(zips: [])->check(PickupMode::DELIVERY, $this->basket($this->dish('Curry')), $day, '67000', $now)), 'no postcode listed: no delivery');
    }

    public function testAChilledParcelLeavesFromMondayToWednesday(): void
    {
        $chain = $this->chain();
        $now = $this->at('2026-10-05 09:00'); // Monday
        $basket = $this->basket($this->dish('Gyoza'), $this->dish('Karaage'));

        $chain->check(PickupMode::SHIPPING, $basket, $this->at('2026-10-06'), null, $now); // Tuesday
        $chain->check(PickupMode::SHIPPING, $basket, $this->at('2026-10-07'), null, $now); // Wednesday
        foreach (['2026-10-08' => 'Thursday', '2026-10-09' => 'Friday', '2026-10-10' => 'Saturday', '2026-10-11' => 'Sunday'] as $day => $name) {
            self::assertSame('takehome.error.parcel_day', $this->refusal(fn () => $chain->check(PickupMode::SHIPPING, $basket, $this->at($day), null, $now)), $name);
        }
        self::assertSame(['2026-10-06', '2026-10-07', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-19'], array_map(fn ($d) => $d->format('Y-m-d'), $chain->shippingDays($now)));
        self::assertSame('2026-10-07', $chain->arrival($this->at('2026-10-06'))->format('Y-m-d'));
    }

    public function testOrderedOnAThursdayTheParcelWaitsForMonday(): void
    {
        $chain = $this->chain();
        $thursday = $this->at('2026-10-08 10:00');
        $basket = $this->basket($this->dish('Gyoza'));

        self::assertSame('takehome.error.parcel_day', $this->refusal(fn () => $chain->check(PickupMode::SHIPPING, $basket, $this->at('2026-10-09'), null, $thursday)));
        self::assertSame('2026-10-12', $chain->shippingDays($thursday)[0]->format('Y-m-d'));
        $options = $chain->options($basket, null, $thursday);
        self::assertTrue($options['shipping']->available);
        self::assertSame('2026-10-12', $options['shipping']->days[0]->format('Y-m-d'));
        self::assertTrue($options['pickup']->available);
        self::assertSame('2026-10-09', $options['pickup']->days[0]->format('Y-m-d'), 'collected the next day, a Friday');
    }

    public function testTheWholeBasketMustTravelAndKeep(): void
    {
        $chain = $this->chain();
        $now = $this->at('2026-10-05 09:00');
        $tuesday = $this->at('2026-10-06');

        self::assertSame('takehome.error.parcel_dish', $this->refusal(fn () => $chain->check(PickupMode::SHIPPING, $this->basket($this->dish('Gyoza'), $this->dish('Œuf mariné', parcel: false)), $tuesday, null, $now)));
        // One day on the road, two days left on arrival: three days of shelf life at least.
        self::assertSame('takehome.error.parcel_shelf_life', $this->refusal(fn () => $chain->check(PickupMode::SHIPPING, $this->basket($this->dish('Gyoza'), $this->dish('Sashimi', shelfLife: 2)), $tuesday, null, $now)));
        $chain->check(PickupMode::SHIPPING, $this->basket($this->dish('Bouillon', shelfLife: 3)), $tuesday, null, $now);

        self::assertCount(1, $chain->travelling($this->basket($this->dish('Gyoza'), $this->dish('Œuf mariné', parcel: false), $this->dish('Sashimi', shelfLife: 2))));

        $options = $chain->options($this->basket($this->dish('Œuf mariné', parcel: false)), '67000', $now);
        self::assertFalse($options['shipping']->available);
        self::assertSame('takehome.error.parcel_dish', $options['shipping']->reason);
        self::assertSame(['dish' => 'Œuf mariné'], $options['shipping']->parameters);
        self::assertTrue($options['pickup']->available);
        self::assertTrue($options['delivery']->available);
    }

    public function testNoCarrierNoParcel(): void
    {
        $now = $this->at('2026-10-05 09:00');
        $basket = $this->basket($this->dish('Gyoza'));

        self::assertSame('takehome.error.mode', $this->refusal(fn () => $this->chain(carrier: false)->check(PickupMode::SHIPPING, $basket, $this->at('2026-10-06'), null, $now)));
        self::assertFalse($this->chain(carrier: false)->options($basket, null, $now)['shipping']->available);
        self::assertSame('takehome.error.mode', $this->refusal(fn () => $this->chain(parcel: ['enabled' => false])->check(PickupMode::SHIPPING, $basket, $this->at('2026-10-06'), null, $now)));
    }

    public function testMadeToOrderTodayAParcelLeavesTomorrowOnceTheCutOffIsPast(): void
    {
        $chain = $this->chain(notice: 0);
        $basket = $this->basket($this->dish('Gyoza'));

        $chain->check(PickupMode::SHIPPING, $basket, $this->at('2026-10-05'), null, $this->at('2026-10-05 09:00'));
        self::assertSame('takehome.error.too_soon', $this->refusal(fn () => $chain->check(PickupMode::SHIPPING, $basket, $this->at('2026-10-05'), null, $this->at('2026-10-05 14:00'))));
    }

    public function testTheBasketsUseByDateIsItsShortest(): void
    {
        $made = $this->at('2026-10-06');
        $basket = $this->basket($this->dish('Gyoza', 5), $this->dish('Bouillon', 3));

        self::assertSame('2026-10-09', $this->chain()->useBy($basket, $made)->format('Y-m-d'));
        self::assertNull($this->chain()->useBy([], $made));
    }
}
