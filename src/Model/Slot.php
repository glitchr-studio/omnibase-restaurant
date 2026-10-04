<?php

namespace Base\Restaurant\Model;

use Base\Restaurant\Entity\MealService;
use Base\Restaurant\Entity\Table;

/** A time a party can be seated at, in which service, at which tables, for how long. */
final readonly class Slot
{
    /** @param list<Table> $tables */
    public function __construct(
        public string $day,
        public string $time,
        public MealService $service,
        public array $tables,
        public int $minutes,
    ) {
    }

    public function startsAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->day.' '.$this->time.':00');
    }

    public function tablesLabel(): string
    {
        return implode('+', array_map(fn (Table $t) => $t->getLabel(), $this->tables));
    }
}
