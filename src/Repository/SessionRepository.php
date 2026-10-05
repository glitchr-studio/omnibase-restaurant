<?php

namespace Base\Restaurant\Repository;

use Base\Restaurant\Entity\Order\Session;
use Base\Restaurant\Entity\Table;
use Base\Restaurant\Enum\SessionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Session> */
class SessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Session::class);
    }

    public function openAt(Table $table): ?Session
    {
        return $this->createQueryBuilder('s')
            ->where('s.table = :table AND s.status != :settled')
            ->setParameter('table', $table)->setParameter('settled', SessionStatus::SETTLED)
            ->orderBy('s.id', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return array<int, Session> table id => its open session */
    public function openByTable(): array
    {
        $open = [];
        foreach ($this->createQueryBuilder('s')->leftJoin('s.tickets', 't')->addSelect('t')->leftJoin('t.lines', 'l')->addSelect('l')
            ->where('s.status != :settled')->setParameter('settled', SessionStatus::SETTLED)
            ->orderBy('s.id', 'ASC')->getQuery()->getResult() as $session) {
            $open[$session->getTable()->getId()] = $session;
        }

        return $open;
    }

    public function revision(): int
    {
        $last = $this->createQueryBuilder('s')->select('MAX(s.updatedAt)')->getQuery()->getSingleScalarResult();

        return $last ? (int) (new \DateTimeImmutable($last))->format('Uv') : 0;
    }

    /** @return list<Session> settled since then, newest first */
    public function settledSince(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :settled AND s.settledAt >= :since')
            ->setParameter('settled', SessionStatus::SETTLED)->setParameter('since', \Base\Database\Type\Utc::from($since))
            ->orderBy('s.settledAt', 'DESC')->getQuery()->getResult();
    }
}
