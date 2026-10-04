<?php

namespace Base\Restaurant\Service;

use Base\Restaurant\Entity\Layout;
use Base\Restaurant\Entity\MealService;
use Base\Restaurant\Entity\Room;
use Base\Restaurant\Entity\Table;
use Base\Restaurant\Model\Floor;
use Base\Restaurant\Repository\RoomRepository;

/**
 * Which layout each room is in for a service on a day - one taking over
 * that date, else the service's (when it is this room's), else the room's
 * default, else the tables where they stand - and so the tables in use and
 * those joined.
 */
class FloorPlan
{
    public function __construct(private readonly RoomRepository $rooms)
    {
    }

    public function layoutOf(Room $room, ?\DateTimeInterface $day = null, ?MealService $service = null): ?Layout
    {
        if ($day) {
            foreach ($room->getLayouts() as $layout) {
                if ($layout->takesOver($day)) {
                    return $layout;
                }
            }
        }
        $own = $service?->getLayout();
        if ($own && $own->getRoom() === $room) {
            return $own;
        }

        return $room->getDefaultLayout();
    }

    /** @param list<Room>|null $rooms every room when null */
    public function floor(?\DateTimeInterface $day = null, ?MealService $service = null, ?array $rooms = null): Floor
    {
        $tables = [];
        $joins = [];
        foreach ($rooms ?? $this->rooms->ordered() as $room) {
            $layout = $this->layoutOf($room, $day, $service);
            $here = [];
            foreach ($room->getTables() as $table) {
                if ($layout ? $layout->uses($table) : $table->isActive()) {
                    $tables[] = $table;
                    $here[(int) $table->getId()] = true;
                }
            }
            foreach ($layout?->getJoins() ?? [] as $group) {
                if (\count(array_filter($group, fn (int $id) => isset($here[$id]))) === \count($group)) {
                    $joins[] = $group;
                }
            }
        }

        return new Floor($tables, $joins);
    }

    /**
     * The room drawn as its layout puts it: each table with its place there.
     *
     * @return array{room: Room, layout: ?Layout, tables: list<array{table: Table, x: int, y: int, rotation: int, active: bool}>, joins: list<list<int>>}
     */
    public function drawing(Room $room, ?Layout $layout): array
    {
        $tables = [];
        foreach ($room->getTables() as $table) {
            $p = $layout ? $layout->positionOf($table) : ['x' => $table->getX(), 'y' => $table->getY(), 'rotation' => $table->getRotation(), 'active' => true];
            $tables[] = ['table' => $table] + $p + ['active' => $table->isActive() && $p['active']];
        }

        return ['room' => $room, 'layout' => $layout, 'tables' => $tables, 'joins' => $layout?->getJoins() ?? []];
    }
}
