<?php

namespace Base\Restaurant\Tests;

use Base\Restaurant\Enum\Station;
use Base\Restaurant\Enum\TicketChannel;
use Base\Restaurant\Enum\TicketStatus;
use Base\Restaurant\Omnifood\OrderTickets;
use Omnifood\Channel;
use Omnifood\Model\Customer;
use Omnifood\Model\Line;
use Omnifood\Model\Modifier;
use Omnifood\Model\Money;
use Omnifood\Model\Order;
use Omnifood\Model\OrderStatus;
use Omnifood\Model\OrderType;
use PHPUnit\Framework\TestCase;

/** A platform's order made a ticket (needs glitchr/omnifood: skipped without it). */
class OrderTicketsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Order::class)) {
            self::markTestSkipped('glitchr/omnifood is not installed.');
        }
    }

    private function order(OrderStatus $status = OrderStatus::NEW): Order
    {
        return new Order(
            Channel::UBEREATS, 'order-123', 'A1B2C', OrderType::DELIVERY, $status,
            [
                new Line('Shōyu ramen', 2, Money::of(1390, 'EUR'), Money::of(2980, 'EUR'), 'shoyu-ramen', null, [new Modifier('Œuf mariné', 2, Money::of(100, 'EUR'), null, 'Suppléments', [new Modifier('Bien cuit')])], 'sans menma'),
                new Line('Ramune', 1, null, Money::of(400, 'EUR')),
            ],
            new Customer('Camille D.'),
            Money::of(3380, 'EUR'),
            placedAt: new \DateTimeImmutable('2026-10-06 19:00:00+02:00'),
            pickupAt: new \DateTimeImmutable('2026-10-06 19:25:00+02:00'),
            note: 'Interphone 12',
            cutlery: false,
        );
    }

    public function testAnOrderBecomesATicketOfItsPlatform(): void
    {
        $ticket = OrderTickets::make('ubereats', $this->order(), 690);

        self::assertSame(TicketChannel::PLATFORM, $ticket->getChannel());
        self::assertSame('ubereats', $ticket->getPlatform());
        self::assertSame('order-123', $ticket->getExternalRef());
        self::assertSame('ubereats #A1B2C', $ticket->getLabel());
        self::assertSame('Camille D.', $ticket->getCustomerName());
        self::assertSame(TicketStatus::NEW, $ticket->getStatus());
        self::assertSame(3380, $ticket->getTotal(), 'what the platform says was paid');
        self::assertStringContainsString('Interphone 12', (string) $ticket->getNote());
        self::assertStringContainsString('sans couverts', (string) $ticket->getNote());
        self::assertSame('2026-10-06 17:00', $ticket->getCreatedAt()->format('Y-m-d H:i'), 'kept in UTC');
        self::assertSame('2026-10-06 17:11:30', $ticket->getAcceptBy()->format('Y-m-d H:i:s'), 'Uber Eats waits 11 min 30');
    }

    public function testItsLinesKeepTheirChoicesAndPrices(): void
    {
        $lines = OrderTickets::make('ubereats', $this->order())->getLines()->toArray();

        self::assertCount(2, $lines);
        self::assertSame('Shōyu ramen', $lines[0]->getName());
        self::assertSame(2, $lines[0]->getQuantity());
        self::assertSame(1390, $lines[0]->getUnitPrice());
        self::assertSame(['2 × Œuf mariné', '› Bien cuit'], $lines[0]->getOptions());
        self::assertSame('sans menma', $lines[0]->getNote());
        self::assertSame(400, $lines[1]->getUnitPrice(), 'the unit price from the line\'s total when the platform gives only that');
        self::assertSame(Station::HOT, $lines[1]->getStation(), 'a dish the menu does not know goes to the kitchen');
        self::assertNull(OrderTickets::make('ubereats', $this->order())->getAcceptBy(), 'no delay known: nothing counted down');
    }

    public function testAnOrderAlreadyAnsweredElsewhereArrivesWhereItStands(): void
    {
        self::assertSame(TicketStatus::ACCEPTED, OrderTickets::make('ubereats', $this->order(OrderStatus::ACCEPTED), 690)->getStatus());
        self::assertNull(OrderTickets::make('ubereats', $this->order(OrderStatus::ACCEPTED), 690)->getAcceptBy());
        self::assertSame(TicketStatus::CANCELLED, OrderTickets::status(OrderStatus::CANCELLED));
        self::assertSame(TicketStatus::REFUSED, OrderTickets::status(OrderStatus::DENIED));
        self::assertSame(TicketStatus::SERVED, OrderTickets::status(OrderStatus::PICKED_UP));
        self::assertNull(OrderTickets::status(OrderStatus::UNKNOWN));
    }
}
