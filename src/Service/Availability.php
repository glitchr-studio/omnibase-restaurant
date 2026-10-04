<?php

namespace Base\Restaurant\Service;

use Base\Restaurant\Entity\MealService;
use Base\Restaurant\Entity\Reservation;
use Base\Restaurant\Entity\Table;
use Base\Restaurant\Enum\ReservationStatus;
use Base\Restaurant\Exception\UnavailableException;
use Base\Restaurant\Model\Slot;
use Base\Restaurant\Repository\MealServiceRepository;
use Base\Restaurant\Repository\ReservationRepository;
use Base\Service\OpeningHours;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * The reservation book's free times and its taking: Seating reckons, this
 * reads the day (its services, its layouts, the reservations holding
 * tables) and writes the reservation under a lock on the day - two guests
 * taking the last table at the same second get it once (symfony/lock).
 */
class Availability
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Seating $seating,
        private readonly FloorPlan $plans,
        private readonly MealServiceRepository $services,
        private readonly ReservationRepository $reservations,
        private readonly OpeningHours $hours,
        private readonly LockFactory $locks,
        #[Autowire('%restaurant.reservation.notice%')] private readonly int $notice = 60,
        #[Autowire('%restaurant.reservation.horizon%')] private readonly int $horizon = 60,
        #[Autowire('%restaurant.reservation.max_covers%')] private readonly int $maxCovers = 8,
    ) {
    }

    public function maxCovers(): int
    {
        return $this->maxCovers;
    }

    /** The restaurant's today, in its own time zone. */
    public function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable((new \DateTimeImmutable('now', $this->hours->timezone()))->format('Y-m-d'));
    }

    /** The restaurant's wall-clock now. */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable((new \DateTimeImmutable('now', $this->hours->timezone()))->format('Y-m-d H:i:s'));
    }

    public function lastDay(): \DateTimeImmutable
    {
        return $this->today()->modify(sprintf('+%d days', $this->horizon));
    }

    /**
     * Every time a party can come on that day, service by service.
     * Online ($online): the notice, the horizon, the size limit and each
     * table's minimum hold; the staff may book anything that fits.
     *
     * @return list<Slot>
     */
    public function slots(\DateTimeInterface $day, int $covers, bool $online = true, ?Reservation $except = null): array
    {
        $day = new \DateTimeImmutable($day->format('Y-m-d'));
        if ($online && ($covers > $this->maxCovers || $day < $this->today() || $day > $this->lastDay())) {
            return [];
        }
        $holding = array_values(array_filter($this->reservations->holdingOn($day), fn (Reservation $r) => !$except || $r !== $except));
        $slots = [];
        foreach ($this->services->runningOn($day) as $service) {
            $floor = $this->plans->floor($day, $service);
            array_push($slots, ...$this->seating->slots($service, $day, $covers, $floor, $holding, $online ? new \DateTimeImmutable() : null, $online ? $this->notice : 0, $online));
        }

        return $slots;
    }

    /** @return array<string, list<Slot>> the slots by service name, for a form */
    public function slotsByService(\DateTimeInterface $day, int $covers, bool $online = true): array
    {
        $by = [];
        foreach ($this->slots($day, $covers, $online) as $slot) {
            $by[$slot->service->getName()][] = $slot;
        }

        return $by;
    }

    /**
     * The next days with at least one time for that many, from $from on.
     *
     * @return list<string> Y-m-d
     */
    public function openDays(int $covers, ?\DateTimeInterface $from = null, int $count = 14): array
    {
        $days = [];
        $day = $from ? new \DateTimeImmutable($from->format('Y-m-d')) : $this->today();
        for ($i = 0; $i <= $this->horizon && \count($days) < $count; ++$i, $day = $day->modify('+1 day')) {
            if ($this->slots($day, $covers)) {
                $days[] = $day->format('Y-m-d');
            }
        }

        return $days;
    }

    /**
     * Takes (or moves) the reservation to that day and time: the tables
     * given, the service and the length set, confirmed or asked for, saved.
     * Under the day's lock, the free tables are read again.
     *
     * @throws UnavailableException (book.error.unavailable) when it does not fit any more
     */
    public function book(Reservation $reservation, string $day, string $time, bool $online = true): Reservation
    {
        $lock = $this->locks->createLock('restaurant-book-'.$day, 30);
        $lock->acquire(true);
        try {
            $date = new \DateTimeImmutable($day);
            $slot = null;
            foreach ($this->slots($date, $reservation->getCovers(), $online, $reservation->getId() ? $reservation : null) as $candidate) {
                if ($candidate->time === $time) {
                    $slot = $candidate;
                    break;
                }
            }
            if (!$slot && !$online) {
                $slot = $this->forced($reservation, $date, $time);
            }
            if (!$slot) {
                throw new UnavailableException('book.error.unavailable');
            }

            $reservation->schedule($slot->startsAt(), $slot->minutes);
            $reservation->setService($slot->service);
            $reservation->assign($slot->tables);
            if (!$reservation->getId() || ReservationStatus::REQUESTED === $reservation->getStatus()) {
                $reservation->setStatus(!$online || $slot->service->confirmsAtOnce($reservation->getCovers()) ? ReservationStatus::CONFIRMED : ReservationStatus::REQUESTED);
            }
            $this->entityManager->persist($reservation);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        return $reservation;
    }

    /**
     * The staff seat a party at given tables, now or at a time: no check but
     * the lock - the floor is theirs (a walk-in squeezed in, a move by hand).
     *
     * @param list<Table> $tables
     */
    public function seatAt(Reservation $reservation, array $tables): Reservation
    {
        $lock = $this->locks->createLock('restaurant-book-'.$reservation->getDay(), 30);
        $lock->acquire(true);
        try {
            $reservation->assign($tables);
            $this->entityManager->persist($reservation);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        return $reservation;
    }

    /** By the staff, at a time no service offers (a late table): kept the usual length, at the tables that fit now. */
    private function forced(Reservation $reservation, \DateTimeImmutable $date, string $time): ?Slot
    {
        $service = $this->services->at($date, $time) ?? $this->services->runningOn($date)[0] ?? null;
        if (!$service instanceof MealService) {
            return null;
        }
        $minutes = $service->durationFor($reservation->getCovers());
        $from = new \DateTimeImmutable($date->format('Y-m-d').' '.$time);
        $holding = array_values(array_filter($this->reservations->holdingOn($date), fn (Reservation $r) => $r !== $reservation));
        $tables = $this->seating->fit($reservation->getCovers(), $this->plans->floor($date, $service), $this->seating->busy($holding, $from->format('Y-m-d H:i'), $from->modify("+$minutes minutes")->format('Y-m-d H:i')), false);

        return new Slot($date->format('Y-m-d'), $time, $service, $tables ?? [], $minutes);
    }
}
