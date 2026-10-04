<?php

namespace Base\Restaurant\Tests;

use Base\Restaurant\Entity\MealService;
use Base\Restaurant\Entity\Reservation;
use Base\Restaurant\Entity\Table;
use Base\Restaurant\Enum\ReservationStatus;
use Base\Restaurant\Enum\TableShape;
use Base\Restaurant\Model\Floor;

/** What the tests seat people at: tables with ids (no database gives them), a floor, a reservation. */
trait Floors
{
    private function table(int $id, int $max, int $min = 1, bool $bookable = true): Table
    {
        $table = (new Table((string) $id, $max, TableShape::SQUARE))->setCovers($min, $max)->setBookable($bookable)->setPosition($id);
        (new \ReflectionProperty(Table::class, 'id'))->setValue($table, $id);

        return $table;
    }

    /** Two tables of two (1, 2), a table of four (3), one of six kept for the phone (4); 1 and 2 joined. */
    private function floor(): Floor
    {
        return new Floor([$this->table(1, 2), $this->table(2, 2), $this->table(3, 4, 2), $this->table(4, 6, 4, false)], [[1, 2]]);
    }

    private function dinner(): MealService
    {
        return (new MealService('Dîner', '19:00', '21:00'))->setStep(30)->setDurations(['2' => 90, '99' => 120]);
    }

    /** @param list<Table> $tables */
    private function held(string $at, int $minutes, array $tables, int $covers = 2, ?MealService $service = null, ReservationStatus $status = ReservationStatus::CONFIRMED): Reservation
    {
        return (new Reservation(null, $covers, 'x'))->schedule(new \DateTimeImmutable($at), $minutes)->assign($tables)->setService($service)->setStatus($status);
    }
}
