<?php

namespace Base\Restaurant\Tests;

use Base\Database\Type\Utc;
use Base\Database\Type\UtcDateTimeImmutableType;
use Base\Restaurant\Entity\Layout;
use Base\Restaurant\Entity\MealService;
use Base\Restaurant\Entity\Order\Session;
use Base\Restaurant\Entity\Order\Ticket;
use Base\Restaurant\Entity\Order\TicketLine;
use Base\Restaurant\Entity\Room;
use Base\Restaurant\Enum\ReservationStatus;
use Base\Restaurant\Enum\SessionStatus;
use Base\Restaurant\Enum\Station;
use Base\Restaurant\Enum\TicketChannel;
use Base\Restaurant\Enum\TicketStatus;
use Base\Restaurant\Exception\TransitionException;
use Base\Restaurant\Model\Booking;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\TestCase;

class EntitiesTest extends TestCase
{
    use Floors;

    public function testATicketGoesFromNewToServedOneStepAtATime(): void
    {
        $ticket = new Ticket(TicketChannel::TABLE);
        $seen = [];
        while (null !== $ticket->getStatus()->forward()) {
            $ticket->advance();
            $seen[] = $ticket->getStatus()->value;
        }

        self::assertSame(['accepted', 'preparing', 'ready', 'served'], $seen);
        self::assertNotNull($ticket->getAcceptedAt());
        self::assertNotNull($ticket->getReadyAt());
        self::assertNotNull($ticket->getServedAt());
        self::assertTrue($ticket->getStatus()->isFinal());
    }

    public function testATicketCannotSkipBackNorBeServedTwice(): void
    {
        $ticket = (new Ticket(TicketChannel::TABLE))->moveTo(TicketStatus::ACCEPTED)->moveTo(TicketStatus::READY)->moveTo(TicketStatus::SERVED);

        $this->expectException(TransitionException::class);
        $ticket->advance();
    }

    public function testOnlyANewTicketIsRefusedAnOpenOneCancelled(): void
    {
        self::assertTrue(TicketStatus::NEW->canBecome(TicketStatus::REFUSED));
        self::assertFalse(TicketStatus::ACCEPTED->canBecome(TicketStatus::REFUSED));
        self::assertTrue(TicketStatus::PREPARING->canBecome(TicketStatus::CANCELLED));
        self::assertFalse(TicketStatus::SERVED->canBecome(TicketStatus::CANCELLED));
        self::assertFalse(TicketStatus::NEW->canBecome(TicketStatus::SERVED));
        self::assertSame('kitchen', TicketStatus::PREPARING->column());
        self::assertNull(TicketStatus::CANCELLED->column());
    }

    public function testATicketCountsItsLinesByStation(): void
    {
        $ticket = (new Ticket(TicketChannel::TABLE))
            ->addLine(new TicketLine('Ramen', 2, 1390, Station::HOT))
            ->addLine(new TicketLine('Ramune', 3, 400, Station::BAR));

        self::assertSame(5, $ticket->count());
        self::assertSame(2 * 1390 + 3 * 400, $ticket->getTotal());
        self::assertSame([Station::HOT, Station::BAR], $ticket->getStations());
        self::assertCount(1, $ticket->getLinesFor(Station::BAR));
        self::assertCount(2, $ticket->getLinesFor(null));
    }

    public function testAPlatformsOrderIsLateWhenItsDeadlineNears(): void
    {
        $ticket = (new Ticket(TicketChannel::PLATFORM, 'ubereats'))->setAcceptBy(Utc::now()->modify('+100 seconds'));

        self::assertEqualsWithDelta(100, $ticket->secondsToAccept(), 2);
        $ticket->moveTo(TicketStatus::ACCEPTED);
        self::assertNull($ticket->secondsToAccept(), 'answered: nothing is awaited');
    }

    public function testMomentsAreKeptInUtcWhateverThePhpZone(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        try {
            $ticket = new Ticket(TicketChannel::TABLE);
            self::assertSame('UTC', $ticket->getCreatedAt()->getTimezone()->getName());

            // The column is glitchr/omnibase's utc_datetime_immutable: written in UTC, read back as UTC, whatever PHP's zone.
            $column = (new \ReflectionProperty(Ticket::class, 'createdAt'))->getAttributes(Column::class)[0]->newInstance();
            self::assertSame(UtcDateTimeImmutableType::NAME, $column->type);
            $type = new UtcDateTimeImmutableType();
            $platform = new MySQLPlatform();
            $stored = $type->convertToDatabaseValue($ticket->getCreatedAt()->setTimezone(new \DateTimeZone('Asia/Tokyo')), $platform);
            self::assertSame($ticket->getCreatedAt()->format('Y-m-d H:i:s'), $stored);
            $hydrated = $type->convertToPHPValue($stored, $platform);
            self::assertSame('UTC', $hydrated->getTimezone()->getName());
            self::assertSame($ticket->getCreatedAt()->getTimestamp(), $hydrated->getTimestamp());

            // A deadline given in another zone is the same moment.
            $ticket->setAcceptBy(new \DateTimeImmutable('2026-10-05 21:00:00', new \DateTimeZone('Europe/Paris')));
            self::assertSame('2026-10-05 19:00:00', $ticket->getAcceptBy()->format('Y-m-d H:i:s'));
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function testABillAddsItsRoundsButNotTheRefusedOnes(): void
    {
        $session = new Session($this->table(1, 4));
        $session->addTicket((new Ticket())->addLine(new TicketLine('Ramen', 1, 1390)));
        $session->addTicket($refused = (new Ticket())->addLine(new TicketLine('Sake', 1, 790)));
        $refused->moveTo(TicketStatus::REFUSED);
        $session->addTicket((new Ticket())->addLine(new TicketLine('Mochi', 2, 650)));

        self::assertSame([1, 2, 3], array_map(fn (Ticket $t) => $t->getRound(), $session->getTickets()->toArray()));
        self::assertSame(1390 + 1300, $session->getTotal());
        self::assertTrue($session->isWaiting());

        $session->requestBill();
        self::assertSame(SessionStatus::BILL, $session->getStatus());
        $session->settle('card');
        self::assertFalse($session->isOpen());
        self::assertSame(2690, $session->getSettledAmount());
    }

    public function testAReservationHoldsItsTablesUntilItEnds(): void
    {
        $reservation = $this->held('2026-10-06 19:30', 90, [$this->table(1, 2)]);

        self::assertTrue($reservation->overlaps('2026-10-06 20:59', '2026-10-06 22:00'));
        self::assertFalse($reservation->overlaps('2026-10-06 21:00', '2026-10-06 22:00'));
        self::assertFalse($reservation->overlaps('2026-10-06 18:00', '2026-10-06 19:30'));
        self::assertSame('1', $reservation->getTablesLabel());

        $reservation->setStatus(ReservationStatus::CANCELLED);
        self::assertCount(0, $reservation->getTables(), 'cancelled, the table is given back');
        self::assertTrue(ReservationStatus::CONFIRMED->canBecome(ReservationStatus::SEATED));
        self::assertFalse(ReservationStatus::FINISHED->canBecome(ReservationStatus::SEATED));
    }

    public function testAServiceListsItsTimesAndItsDurations(): void
    {
        $service = (new MealService('Déjeuner', '12:00', '13:30'))->setStep(30)->setDurationsText("2: 60\n4: 75\n99: 90")->setAutoConfirm(6);

        self::assertSame(['12:00', '12:30', '13:00', '13:30'], $service->times());
        self::assertSame(60, $service->durationFor(1));
        self::assertSame(75, $service->durationFor(3));
        self::assertSame(90, $service->durationFor(12));
        self::assertTrue($service->confirmsAtOnce(6));
        self::assertFalse($service->confirmsAtOnce(7));
        self::assertTrue($service->covers('13:30'));
        self::assertFalse($service->covers('13:45'));
    }

    public function testALayoutPlacesJoinsAndTakesOverDates(): void
    {
        $room = new Room('Salle');
        $room->addTable($a = $this->table(1, 2))->addTable($b = $this->table(2, 2))->addTable($c = $this->table(3, 4));
        $layout = (new Layout('Soir', $room))->place($a, 10, 20, 90)->place($c, 0, 0, 0, false);
        $layout->join($a, $b)->setJoins([...$layout->getJoins(), [2, 3], [3]]);
        $layout->setDates("2026-12-31\nnot a date, 2026-12-24");

        self::assertSame(['x' => 10, 'y' => 20, 'rotation' => 90, 'active' => true], $layout->positionOf($a));
        self::assertSame($b->getX(), $layout->positionOf($b)['x'], 'a table the layout does not place stands where it is');
        self::assertFalse($layout->uses($c));
        self::assertSame([[1, 2]], $layout->getJoins(), 'a table is in one group only; a group is two tables at least');
        self::assertSame(['2026-12-24', '2026-12-31'], $layout->getDates());
        self::assertTrue($layout->takesOver(new \DateTimeImmutable('2026-12-31')));
        self::assertSame(4, $room->getCapacity() - 4);
    }

    public function testTheBookingFormKnowsARobot(): void
    {
        $booking = new Booking();
        $booking->startedAt = 1000;

        self::assertTrue($booking->isRobot(3, 1001), 'sent a second after it was shown');
        self::assertFalse($booking->isRobot(3, 1010));
        $booking->website = 'https://spam.example';
        self::assertTrue($booking->isRobot(3, 1010), 'the trap filled');
    }

    public function testSettlingATableLeavesNoneOfItsRoundsOnThePass(): void
    {
        $session = new Session($this->table(12, 4));
        $rounds = [];
        foreach (['served' => 4, 'ready' => 3, 'preparing' => 2, 'accepted' => 1, 'new' => 0] as $name => $steps) {
            $ticket = new Ticket(TicketChannel::TABLE);
            $ticket->addLine(new TicketLine('Ramen', 1, 1400));
            for ($i = 0; $i < $steps; ++$i) {
                $ticket->advance();
            }
            $session->addTicket($ticket);
            $rounds[$name] = $ticket;
        }
        $refused = (new Ticket(TicketChannel::TABLE))->addLine(new TicketLine('Gyoza', 1, 700));
        $refused->moveTo(TicketStatus::REFUSED);
        $session->addTicket($refused);
        self::assertSame(7000, $session->getTotal(), 'five rounds billed');

        $session->settle('card');

        self::assertSame(SessionStatus::SETTLED, $session->getStatus());
        foreach ($rounds as $name => $ticket) {
            self::assertSame(TicketStatus::SERVED, $ticket->getStatus(), $name);
            self::assertNotNull($ticket->getServedAt(), $name);
            self::assertNotNull($ticket->getAcceptedAt(), $name);
            self::assertNotNull($ticket->getReadyAt(), $name);
        }
        self::assertSame(TicketStatus::REFUSED, $refused->getStatus(), 'a round refused stays refused');
        self::assertSame(7000, $session->getSettledAmount(), 'settling changes nothing of what is billed');
        foreach ($session->getTickets() as $ticket) {
            self::assertFalse($ticket->getStatus()->isOpen());
        }
        self::assertSame([], $session->closeTickets(), 'nothing left to close');
    }

    public function testARoundsOptionsAreIdsAndWords(): void
    {
        $line = new \Base\Restaurant\Model\RoundLine();
        $line->options = [12, '15', 'sans oignons', ''];

        self::assertSame([12, 15], $line->optionIds());
        self::assertSame(['sans oignons'], $line->optionWords());
    }
}
