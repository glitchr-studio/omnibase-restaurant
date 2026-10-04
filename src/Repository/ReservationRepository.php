<?php

namespace Base\Restaurant\Repository;

use Base\Restaurant\Entity\Reservation;
use Base\Restaurant\Enum\ReservationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reservations are kept in the restaurant's wall-clock time: days and
 * hours are compared as written ("2026-10-04 20:00"), never through a time zone.
 *
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    public function findOneByToken(string $token): ?Reservation
    {
        return '' === $token ? null : $this->findOneBy(['token' => $token]);
    }

    public function findOneByExternal(string $source, string $ref): ?Reservation
    {
        return $this->findOneBy(['source' => $source, 'externalRef' => $ref]);
    }

    /** @return list<Reservation> that day's, by time, whatever their status */
    public function onDay(\DateTimeInterface $day): array
    {
        [$from, $to] = self::bounds($day);

        return $this->createQueryBuilder('r')
            ->leftJoin('r.tables', 't')->addSelect('t')
            ->where('r.startsAt >= :from AND r.startsAt < :to')
            ->setParameter('from', $from, Types::DATETIME_IMMUTABLE)->setParameter('to', $to, Types::DATETIME_IMMUTABLE)
            ->orderBy('r.startsAt', 'ASC')->addOrderBy('r.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<Reservation> that day's still holding their tables (asked, confirmed, seated) */
    public function holdingOn(\DateTimeInterface $day): array
    {
        return array_values(array_filter($this->onDay($day), fn (Reservation $r) => $r->holds()));
    }

    /** @return list<Reservation> asked for and not answered yet, from today on */
    public function toConfirm(\DateTimeInterface $today): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status AND r.startsAt >= :from')
            ->setParameter('status', ReservationStatus::REQUESTED)
            ->setParameter('from', self::bounds($today)[0], Types::DATETIME_IMMUTABLE)
            ->orderBy('r.startsAt', 'ASC')
            ->getQuery()->getResult();
    }

    public function coversOn(\DateTimeInterface $day): int
    {
        return array_sum(array_map(fn (Reservation $r) => $r->getCovers(), $this->holdingOn($day)));
    }

    /** Microseconds of the last change, for the pass's polling. */
    public function revision(): int
    {
        $last = $this->createQueryBuilder('r')->select('MAX(r.updatedAt)')->getQuery()->getSingleScalarResult();

        return $last ? (int) (new \DateTimeImmutable($last))->format('Uv') : 0;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} the day's wall-clock bounds */
    public static function bounds(\DateTimeInterface $day): array
    {
        $from = new \DateTimeImmutable($day->format('Y-m-d').' 00:00:00');

        return [$from, $from->modify('+1 day')];
    }
}
