<?php

namespace Base\Restaurant\Model;

use Base\Restaurant\Entity\Table;

/**
 * The tables in use for a service on a day, and those pushed together - as
 * the layouts of the rooms say (Service\FloorPlan).
 */
final readonly class Floor
{
    /**
     * @param list<Table>     $tables
     * @param list<list<int>> $joins  groups of table ids, each among $tables
     */
    public function __construct(public array $tables, public array $joins = [])
    {
    }

    public function table(int $id): ?Table
    {
        foreach ($this->tables as $table) {
            if ($table->getId() === $id) {
                return $table;
            }
        }

        return null;
    }

    public function capacity(): int
    {
        return array_sum(array_map(fn (Table $t) => $t->getMaxCovers(), $this->tables));
    }
}
