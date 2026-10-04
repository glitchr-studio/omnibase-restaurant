<?php

namespace Base\Restaurant\Repository;

use Base\Restaurant\Entity\Product\TakeHome;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TakeHome> */
class TakeHomeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TakeHome::class);
    }

    /** @return list<TakeHome> the shop's dishes in its order; those withdrawn from sale left out */
    public function shop(bool $onSaleOnly = true): array
    {
        $dishes = $this->findAll();
        if ($onSaleOnly) {
            $dishes = array_filter($dishes, fn (TakeHome $d) => $d->isForSell() || \Base\Marketplace\Enum\ProductAvailability::OUT_OF_STOCK === $d->getAvailability());
        }
        usort($dishes, fn (TakeHome $a, TakeHome $b) => [$a->getShopPosition(), $a->getId()] <=> [$b->getShopPosition(), $b->getId()]);

        return array_values($dishes);
    }

    public function findOneBySlug(string $slug): ?TakeHome
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
