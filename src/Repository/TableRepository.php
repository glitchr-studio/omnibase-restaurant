<?php

namespace Base\Restaurant\Repository;

use Base\Restaurant\Entity\Table;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Table> */
class TableRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Table::class);
    }

    /** @return list<Table> */
    public function ordered(): array
    {
        return $this->findBy([], ["position" => "ASC", "id" => "ASC"]);
    }

    public function findOneByToken(string $token): ?Table
    {
        return '' === $token ? null : $this->findOneBy(['token' => $token]);
    }

    /** @return list<Table> every table in use, room by room */
    public function active(): array
    {
        return $this->createQueryBuilder('t')->join('t.room', 'r')->addSelect('r')
            ->where('t.active = true')
            ->orderBy('r.position', 'ASC')->addOrderBy('r.id', 'ASC')->addOrderBy('t.position', 'ASC')->addOrderBy('t.id', 'ASC')
            ->getQuery()->getResult();
    }
}
