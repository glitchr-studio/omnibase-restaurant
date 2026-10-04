<?php

namespace Base\Restaurant\Repository;

use Base\Restaurant\Entity\Layout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Layout> */
class LayoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Layout::class);
    }

    /** @return list<Layout> */
    public function ordered(): array
    {
        return $this->findBy([], ["name" => "ASC"]);
    }
}
