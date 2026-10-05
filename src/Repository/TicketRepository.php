<?php

namespace Base\Restaurant\Repository;

use Base\Restaurant\Entity\Order\Ticket;
use Base\Restaurant\Enum\TicketStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Ticket> */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    public function findOneByExternal(string $platform, string $ref): ?Ticket
    {
        return $this->findOneBy(['platform' => $platform, 'externalRef' => $ref]);
    }

    /**
     * @return list<Ticket> what the pass shows: every ticket not finished,
     *                      and those served in the last $servedFor minutes
     */
    public function onThePass(int $servedFor = 20): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.lines', 'l')->addSelect('l')
            ->leftJoin('t.table', 'tb')->addSelect('tb')
            ->where('t.status IN (:open) OR (t.status = :served AND t.servedAt >= :since)')
            ->setParameter('open', [TicketStatus::NEW, TicketStatus::ACCEPTED, TicketStatus::PREPARING, TicketStatus::READY])
            ->setParameter('served', TicketStatus::SERVED)
            ->setParameter('since', \Base\Database\Type\Utc::now()->modify(sprintf('-%d minutes', $servedFor)))
            ->orderBy('t.createdAt', 'ASC')->addOrderBy('t.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** The newest ticket waiting for an answer: the pass rings when it grows. */
    public function latestNew(): int
    {
        return (int) $this->createQueryBuilder('t')->select('MAX(t.id)')
            ->where('t.status = :new')->setParameter('new', TicketStatus::NEW)
            ->getQuery()->getSingleScalarResult();
    }

    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('t')->select('COUNT(t.id)')
            ->where('t.status IN (:open)')->setParameter('open', [TicketStatus::NEW, TicketStatus::ACCEPTED, TicketStatus::PREPARING, TicketStatus::READY])
            ->getQuery()->getSingleScalarResult();
    }

    public function revision(): int
    {
        $last = $this->createQueryBuilder('t')->select('MAX(t.updatedAt)')->getQuery()->getSingleScalarResult();

        return $last ? (int) (new \DateTimeImmutable($last))->format('Uv') : 0;
    }
}
