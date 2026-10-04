<?php

namespace Base\Restaurant\Repository;

use Base\Restaurant\Entity\Menu\Dish;
use Base\Restaurant\Entity\Menu\MenuSection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Dish> */
class DishRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Dish::class);
    }

    /** @return list<Dish> every dish (set menus included) in the menu's order */
    public function menu(): array
    {
        $dishes = $this->findAll();
        usort($dishes, fn (Dish $a, Dish $b) => [$a->getSection()?->getMenuPosition() ?? 999, $a->getMenuPosition(), $a->getId()] <=> [$b->getSection()?->getMenuPosition() ?? 999, $b->getMenuPosition(), $b->getId()]);

        return $dishes;
    }

    /** @return list<array{section: ?MenuSection, dishes: list<Dish>}> the menu by section, sections in their order, the dishes without one last */
    public function bySection(bool $onSaleOnly = true): array
    {
        $groups = [];
        foreach ($this->menu() as $dish) {
            if ($onSaleOnly && !$dish->isOnMenu()) {
                continue;
            }
            $key = $dish->getSection()?->getId() ?? 0;
            $groups[$key] ??= ['section' => $dish->getSection(), 'dishes' => []];
            $groups[$key]['dishes'][] = $dish;
        }

        return array_values($groups);
    }
}
