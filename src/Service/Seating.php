<?php

namespace Base\Restaurant\Service;

use Base\Restaurant\Entity\MealService;
use Base\Restaurant\Entity\Reservation;
use Base\Restaurant\Entity\Table;
use Base\Restaurant\Model\Floor;
use Base\Restaurant\Model\Slot;
use Base\Service\OpeningHours;

/**
 * Where and when a party fits - the reckoning alone, nothing read nor
 * written (Availability does that, under a lock):
 *
 *  - a time is offered when the service runs that day, the place is open
 *    then (glitchr/omnibase's OpeningHours: the usual week and the days
 *    off), it is far enough ahead, the service has covers left, and a table
 *    - or tables joined in the layout - is free for as long as such a party
 *    keeps it;
 *  - the table given is the smallest that seats the party (a table's minimum
 *    is kept online, not by the staff); joined tables only when no single
 *    one does, the smallest group first.
 *
 * Every time is the restaurant's wall-clock time, compared as written.
 */
class Seating
{
    public function __construct(private readonly OpeningHours $hours)
    {
    }

    /**
     * The times a party of $covers can be seated at in $service on $day.
     *
     * @param list<Reservation> $holding the reservations holding tables that day
     * @param int               $notice  minutes from $now before the first time offered
     *
     * @return list<Slot>
     */
    public function slots(MealService $service, \DateTimeInterface $day, int $covers, Floor $floor, array $holding, ?\DateTimeInterface $now = null, int $notice = 0, bool $strict = true): array
    {
        if ($covers < 1 || !$service->runsOn($day)) {
            return [];
        }
        $hours = $this->hours->hoursOn($day);
        if (!$hours) {
            return [];
        }
        $date = $day->format('Y-m-d');
        if ($service->getMaxCovers() && $this->coversTaken($service, $holding) + $covers > $service->getMaxCovers()) {
            return [];
        }
        $earliest = $now ? \DateTimeImmutable::createFromInterface($now)->setTimezone($this->hours->timezone())->modify(sprintf('+%d minutes', $notice))->format('Y-m-d H:i') : null;
        $minutes = $service->durationFor($covers);

        $slots = [];
        foreach ($service->times() as $time) {
            if (!self::within($time, $hours) || ($earliest && $date.' '.$time < $earliest)) {
                continue;
            }
            $from = new \DateTimeImmutable($date.' '.$time);
            $until = $from->modify(sprintf('+%d minutes', $minutes));
            $tables = $this->fit($covers, $floor, $this->busy($holding, $from->format('Y-m-d H:i'), $until->format('Y-m-d H:i')), $strict);
            if ($tables) {
                $slots[] = new Slot($date, $time, $service, $tables, $minutes);
            }
        }

        return $slots;
    }

    /**
     * The tables for a party, among those not $busy: the smallest single
     * table first, else the smallest group of joined tables. $strict (online)
     * keeps the tables that are not offered online and each table's minimum.
     *
     * @param array<int, true> $busy table id => busy
     *
     * @return list<Table>|null
     */
    public function fit(int $covers, Floor $floor, array $busy, bool $strict = true): ?array
    {
        $free = fn (Table $t) => !isset($busy[$t->getId()]) && (!$strict || $t->isBookable());

        $best = null;
        foreach ($floor->tables as $table) {
            if (!$free($table) || $table->getMaxCovers() < $covers || ($strict && $table->getMinCovers() > $covers)) {
                continue;
            }
            $key = [$table->getMaxCovers(), $table->getMinCovers() > $covers ? 1 : 0, $table->getPosition(), $table->getId()];
            if (null === $best || $key < $best[0]) {
                $best = [$key, [$table]];
            }
        }
        if ($best) {
            return $best[1];
        }

        foreach ($floor->joins as $ids) {
            $group = array_values(array_filter(array_map(fn (int $id) => $floor->table($id), $ids)));
            if (\count($group) !== \count($ids) || \count(array_filter($group, $free)) !== \count($group)) {
                continue;
            }
            $capacity = array_sum(array_map(fn (Table $t) => $t->getMaxCovers(), $group));
            if ($capacity < $covers) {
                continue;
            }
            $key = [$capacity, \count($group), min(array_map(fn (Table $t) => $t->getId(), $group))];
            if (null === $best || $key < $best[0]) {
                $best = [$key, $group];
            }
        }

        return $best[1] ?? null;
    }

    /**
     * The tables held between those two wall-clock times ("Y-m-d H:i").
     *
     * @param list<Reservation> $holding
     *
     * @return array<int, true>
     */
    public function busy(array $holding, string $from, string $until): array
    {
        $busy = [];
        foreach ($holding as $reservation) {
            if (!$reservation->holds() || !$reservation->overlaps($from, $until)) {
                continue;
            }
            foreach ($reservation->getTables() as $table) {
                $busy[(int) $table->getId()] = true;
            }
        }

        return $busy;
    }

    /** @param list<Reservation> $holding */
    public function coversTaken(MealService $service, array $holding): int
    {
        $taken = 0;
        foreach ($holding as $reservation) {
            $own = $reservation->getService();
            if ($reservation->holds() && ($own ? $own === $service || ($own->getId() && $own->getId() === $service->getId()) : $service->covers($reservation->getTime()))) {
                $taken += $reservation->getCovers();
            }
        }

        return $taken;
    }

    /** @param array<array{0: string, 1: string}> $hours */
    private static function within(string $time, array $hours): bool
    {
        foreach ($hours as [$open, $close]) {
            if ($open <= $time && $time < $close) {
                return true;
            }
        }

        return false;
    }
}
