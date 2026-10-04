<?php

namespace Base\Restaurant\Tests;

use Base\Entity\Hours\SpecialDay;
use Base\Restaurant\Enum\ReservationStatus;
use Base\Restaurant\Model\Slot;
use Base\Restaurant\Service\Seating;
use Base\Service\OpeningHours;
use PHPUnit\Framework\TestCase;

class SeatingTest extends TestCase
{
    use Floors;

    /** A Tuesday. */
    private const DAY = '2026-10-06';

    private function seating(array $specialDays = []): Seating
    {
        $hours = (new OpeningHours(null, null, 'Europe/Paris'))
            ->withWeek([2 => [['11:45', '14:30'], ['18:45', '22:30']], 3 => [['18:45', '20:15']]])
            ->withSpecialDays($specialDays);

        return new Seating($hours);
    }

    /** @param list<Slot> $slots */
    private static function times(array $slots): array
    {
        return array_map(fn (Slot $s) => $s->time, $slots);
    }

    public function testEveryTimeOfTheServiceIsOfferedOnAnEmptyFloor(): void
    {
        $slots = $this->seating()->slots($this->dinner(), new \DateTimeImmutable(self::DAY), 2, $this->floor(), []);

        self::assertSame(['19:00', '19:30', '20:00', '20:30', '21:00'], self::times($slots));
        self::assertSame(90, $slots[0]->minutes);
        self::assertSame('1', $slots[0]->tablesLabel(), 'the smallest table that seats two');
    }

    public function testTheSmallestTableThatFitsIsGiven(): void
    {
        $seating = $this->seating();
        $floor = $this->floor();

        self::assertSame([3], array_map(fn ($t) => $t->getId(), $seating->fit(3, $floor, [])));
        self::assertSame([3], array_map(fn ($t) => $t->getId(), $seating->fit(4, $floor, [])), 'a single table before joined ones');
        self::assertSame([2], array_map(fn ($t) => $t->getId(), $seating->fit(2, $floor, [1 => true])));
    }

    public function testJoinedTablesSeatAPartyNoSingleTableTakes(): void
    {
        $seating = $this->seating();
        $floor = $this->floor();

        self::assertSame([1, 2], array_map(fn ($t) => $t->getId(), $seating->fit(4, $floor, [3 => true])), 'the four-top taken: the two two-tops pushed together');
        self::assertNull($seating->fit(4, $floor, [3 => true, 1 => true]), 'a joined group needs all its tables free');
        self::assertNull($seating->fit(5, $floor, []), 'online, the six-top is not offered and nothing else seats five');
    }

    public function testTheStaffMayUseWhatIsNotOfferedOnline(): void
    {
        $seating = $this->seating();

        self::assertSame([4], array_map(fn ($t) => $t->getId(), $seating->fit(5, $this->floor(), [], false)));
        self::assertNull($seating->fit(1, new \Base\Restaurant\Model\Floor([$this->table(3, 4, 2)]), []), 'online, a table\'s minimum holds');
        self::assertNotNull($seating->fit(1, new \Base\Restaurant\Model\Floor([$this->table(3, 4, 2)]), [], false));
    }

    public function testATableHeldIsNotOfferedWhileItIsKept(): void
    {
        $floor = new \Base\Restaurant\Model\Floor([$table = $this->table(1, 2)]);
        // 19:30 for 90 minutes: until 21:00.
        $holding = [$this->held(self::DAY.' 19:30', 90, [$table])];

        $slots = $this->seating()->slots($this->dinner(), new \DateTimeImmutable(self::DAY), 2, $floor, $holding);

        self::assertSame(['21:00'], self::times($slots), '19:00 would still be there at 19:30; 21:00 starts as the table is given back');
    }

    public function testACancelledReservationHoldsNothing(): void
    {
        $floor = new \Base\Restaurant\Model\Floor([$table = $this->table(1, 2)]);
        $cancelled = $this->held(self::DAY.' 19:30', 90, [$table], 2, null, ReservationStatus::CANCELLED);

        self::assertCount(5, $this->seating()->slots($this->dinner(), new \DateTimeImmutable(self::DAY), 2, $floor, [$cancelled]));
    }

    public function testAClosedDayOffersNothing(): void
    {
        $closed = new SpecialDay(new \DateTimeImmutable(self::DAY), new \DateTimeImmutable(self::DAY), 'Fermeture exceptionnelle');

        self::assertSame([], $this->seating([$closed])->slots($this->dinner(), new \DateTimeImmutable(self::DAY), 2, $this->floor(), []));
        self::assertSame([], $this->seating()->slots($this->dinner(), new \DateTimeImmutable('2026-10-05'), 2, $this->floor(), []), 'a Monday: closed in the usual week');
    }

    public function testOnlyTheTimesTheHouseIsOpenAreOffered(): void
    {
        // A Wednesday: open 18:45-20:15 only.
        $slots = $this->seating()->slots($this->dinner(), new \DateTimeImmutable('2026-10-07'), 2, $this->floor(), []);

        self::assertSame(['19:00', '19:30', '20:00'], self::times($slots));
    }

    public function testADayTheServiceDoesNotRunOffersNothing(): void
    {
        $service = $this->dinner()->setWeekdays([5, 6]);

        self::assertSame([], $this->seating()->slots($service, new \DateTimeImmutable(self::DAY), 2, $this->floor(), []));
    }

    public function testTheNoticeHidesTheTimesTooClose(): void
    {
        // 19:10 in Paris that Tuesday (UTC+2), an hour's notice: nothing before 20:10.
        $now = new \DateTimeImmutable(self::DAY.' 17:10:00', new \DateTimeZone('UTC'));

        $slots = $this->seating()->slots($this->dinner(), new \DateTimeImmutable(self::DAY), 2, $this->floor(), [], $now, 60);

        self::assertSame(['20:30', '21:00'], self::times($slots));
    }

    public function testTheServicesCoversAreALimitOfTheirOwn(): void
    {
        $service = $this->dinner()->setMaxCovers(5);
        $floor = $this->floor();
        $holding = [$this->held(self::DAY.' 19:00', 120, [$floor->table(3)], 4, $service)];

        self::assertSame([], $this->seating()->slots($service, new \DateTimeImmutable(self::DAY), 2, $floor, $holding), '4 + 2 covers is over the kitchen\'s 5');
        self::assertNotSame([], $this->seating()->slots($service, new \DateTimeImmutable(self::DAY), 1, new \Base\Restaurant\Model\Floor([$this->table(9, 2)]), $holding));
    }

    public function testALargerPartyKeepsItsTableLonger(): void
    {
        $slots = $this->seating()->slots($this->dinner(), new \DateTimeImmutable(self::DAY), 4, $this->floor(), []);

        self::assertSame(120, $slots[0]->minutes);
        self::assertSame('2026-10-06 19:00', $slots[0]->startsAt()->format('Y-m-d H:i'));
    }
}
