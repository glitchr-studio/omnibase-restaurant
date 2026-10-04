<?php

namespace Base\Restaurant\Service;

use Base\Restaurant\Entity\Menu\Dish;
use Base\Restaurant\Entity\Order\Session;
use Base\Restaurant\Entity\Order\Ticket;
use Base\Restaurant\Entity\Reservation;
use Base\Restaurant\Entity\Room;
use Base\Restaurant\Entity\Table;
use Base\Restaurant\Enum\ReservationStatus;
use Base\Restaurant\Enum\SessionStatus;
use Base\Restaurant\Enum\Station;
use Base\Restaurant\Enum\TicketStatus;
use Base\Restaurant\Event\DishAvailabilityEvent;
use Base\Restaurant\Event\TicketMovedEvent;
use Base\Restaurant\Exception\TransitionException;
use Base\Restaurant\Repository\DishRepository;
use Base\Restaurant\Repository\MealServiceRepository;
use Base\Restaurant\Repository\ReservationRepository;
use Base\Restaurant\Repository\RoomRepository;
use Base\Restaurant\Repository\SessionRepository;
use Base\Restaurant\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "Le passe": the one screen the floor runs on. The room as it is now -
 * each table free, booked soon, seated, waiting for the kitchen, with
 * something ready, calling, asking for the bill -, the tickets of every
 * channel in columns (to accept, in the kitchen, ready, served), the day's
 * reservations; and what the staff do there: move a ticket along, settle
 * a table, move guests to another table, run a dish out.
 */
class Pass
{
    /** A table booked within this many minutes shows as booked */
    public const SOON = 45;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TicketRepository $tickets,
        private readonly SessionRepository $sessions,
        private readonly ReservationRepository $reservations,
        private readonly RoomRepository $rooms,
        private readonly MealServiceRepository $services,
        private readonly DishRepository $dishes,
        private readonly FloorPlan $plans,
        private readonly Availability $availability,
        private readonly Revision $revision,
        private readonly EventDispatcherInterface $dispatcher,
        #[Autowire('%restaurant.pass.accept_warning%')] private readonly int $acceptWarning = 180,
    ) {
    }

    /**
     * Everything the screen draws.
     *
     * @return array<string, mixed>
     */
    public function board(?Station $station = null): array
    {
        $now = $this->availability->now();
        $today = $this->availability->today();
        $service = $this->services->at($today, $now->format('H:i')) ?? $this->nextService($today, $now);
        $sessions = $this->sessions->openByTable();
        $reservations = $this->reservations->onDay($today);

        $columns = ['new' => [], 'kitchen' => [], 'ready' => [], 'served' => []];
        foreach ($this->tickets->onThePass() as $ticket) {
            if ($station && !$ticket->getLinesFor($station)) {
                continue;
            }
            $column = $ticket->getStatus()->column();
            if ($column) {
                $columns[$column][] = $ticket;
            }
        }
        $columns['served'] = array_reverse(\array_slice($columns['served'], -8));

        $rooms = [];
        foreach ($this->rooms->ordered() as $room) {
            $drawing = $this->plans->drawing($room, $this->plans->layoutOf($room, $today, $service));
            foreach ($drawing['tables'] as $i => $place) {
                $drawing['tables'][$i] += $this->tableState($place['table'], $sessions[$place['table']->getId()] ?? null, $reservations, $now);
            }
            $rooms[] = $drawing;
        }

        return [
            'now' => $now,
            'service' => $service,
            'station' => $station,
            'columns' => $columns,
            'rooms' => $rooms,
            'sessions' => $sessions,
            'reservations' => $reservations,
            'to_confirm' => $this->reservations->toConfirm($today),
            'covers' => array_sum(array_map(fn (Reservation $r) => $r->getCovers(), array_filter($reservations, fn (Reservation $r) => $r->holds() || ReservationStatus::FINISHED === $r->getStatus()))),
            'accept_warning' => $this->acceptWarning,
            'dishes' => array_values(array_filter($this->dishes->menu(), fn (Dish $d) => $d->isOnMenu())),
            'state' => $this->state(),
        ];
    }

    /** What the pass's poll compares: the revision (redraw) and the newest ticket to accept (ring). */
    public function state(): array
    {
        return [
            'revision' => $this->revision->current(),
            'latest' => $this->tickets->latestNew(),
            'open' => $this->tickets->countOpen(),
        ];
    }

    /**
     * A table as the floor shows it now.
     *
     * @param list<Reservation> $reservations the day's
     *
     * @return array{state: string, session: ?Session, reservation: ?Reservation, next: ?Reservation}
     */
    public function tableState(Table $table, ?Session $session, array $reservations, \DateTimeImmutable $now): array
    {
        $seated = null;
        $next = null;
        $soon = $now->modify(sprintf('+%d minutes', self::SOON))->format('Y-m-d H:i');
        foreach ($reservations as $reservation) {
            if (!$reservation->sits($table)) {
                continue;
            }
            if (ReservationStatus::SEATED === $reservation->getStatus()) {
                $seated = $reservation;
            } elseif ($reservation->holds() && $reservation->getEndsAt()->format('Y-m-d H:i') > $now->format('Y-m-d H:i') && (!$next || $reservation->getStartsAt() < $next->getStartsAt())) {
                $next = $reservation;
            }
        }

        $state = match (true) {
            null !== $session?->getWaiterCalledAt() => 'call',
            SessionStatus::BILL === $session?->getStatus() => 'bill',
            (bool) $session?->hasReady() => 'ready',
            (bool) $session?->isWaiting() => 'waiting',
            null !== $session || null !== $seated => 'seated',
            $next && $next->getStartsAt()->format('Y-m-d H:i') <= $soon => 'booked',
            !$table->isActive() => 'off',
            default => 'free',
        };

        return ['state' => $state, 'session' => $session, 'reservation' => $seated, 'next' => $next];
    }

    /**
     * accept, start, ready, serve, refuse, cancel - or "forward", the next step.
     *
     * @throws TransitionException
     */
    public function move(Ticket $ticket, string $action, ?string $reason = null): void
    {
        $from = $ticket->getStatus();
        $to = match ($action) {
            'forward' => $from->forward(),
            'accept' => TicketStatus::ACCEPTED,
            'start' => TicketStatus::PREPARING,
            'ready' => TicketStatus::READY,
            'serve' => TicketStatus::SERVED,
            'refuse' => TicketStatus::REFUSED,
            'cancel' => TicketStatus::CANCELLED,
            default => null,
        } ?? throw new TransitionException('pass.error.action');
        $ticket->moveTo($to);
        $this->entityManager->flush();
        $this->dispatcher->dispatch(new TicketMovedEvent($ticket, $from, \in_array($to, [TicketStatus::REFUSED, TicketStatus::CANCELLED], true) ? ($reason ?: 'too_busy') : null));
    }

    /**
     * The bill paid (at the till, or from a phone): the table is free, and
     * none of its rounds stays on the pass - what was still open is served
     * (Session::closeTickets()), and the listeners are told of each.
     */
    public function settle(Session $session, string $with = 'till', ?int $amount = null): void
    {
        $before = [];
        foreach ($session->getTickets() as $ticket) {
            $before[spl_object_id($ticket)] = $ticket->getStatus();
        }
        $session->settle($with, $amount);
        $reservation = $session->getReservation();
        if ($reservation && ReservationStatus::SEATED === $reservation->getStatus()) {
            $reservation->setStatus(ReservationStatus::FINISHED);
        }
        $this->entityManager->flush();
        foreach ($session->getTickets() as $ticket) {
            $from = $before[spl_object_id($ticket)] ?? null;
            if ($from !== $ticket->getStatus()) {
                $this->dispatcher->dispatch(new TicketMovedEvent($ticket, $from));
            }
        }
    }

    /** The guests (and their bill, their reservation) moved to another table. */
    public function transfer(Session $session, Table $to): void
    {
        if ($this->sessions->openAt($to)) {
            throw new TransitionException('pass.error.table_taken');
        }
        $from = $session->getTable();
        $session->moveTo($to);
        foreach ($session->getTickets() as $ticket) {
            $ticket->setTable($to);
        }
        $reservation = $session->getReservation();
        if ($reservation) {
            $reservation->assign(array_map(fn (Table $t) => $t === $from ? $to : $t, $reservation->getTables()->toArray()));
        }
        $this->entityManager->flush();
    }

    /** Seat a reservation: its table's bill opened and tied to it. */
    public function seat(Reservation $reservation): ?Session
    {
        $table = $reservation->getTables()->first() ?: null;
        $session = null;
        if ($table instanceof Table) {
            $session = $this->sessions->openAt($table) ?? new Session($table, $reservation->getCovers());
            $session->setReservation($reservation)->setCovers($reservation->getCovers());
            $this->entityManager->persist($session);
        }
        $this->entityManager->flush();

        return $session;
    }

    public function answerCall(Session $session): void
    {
        $session->answerCall();
        $this->entityManager->flush();
    }

    /** Guests may order from their phones at this table, or not any more. */
    public function toggleOrdering(Table $table): void
    {
        $table->setOrdering(!$table->isOrdering());
        $this->entityManager->flush();
    }

    /** Run out ("rupture"), or back. */
    public function stock(Dish $dish, bool $available): void
    {
        $available ? $dish->restock() : $dish->runOut();
        $this->entityManager->flush();
        $this->dispatcher->dispatch(new DishAvailabilityEvent($dish, $available));
    }

    private function nextService(\DateTimeImmutable $today, \DateTimeImmutable $now): ?\Base\Restaurant\Entity\MealService
    {
        foreach ($this->services->runningOn($today) as $service) {
            if ($service->getFirstSeating() > $now->format('H:i')) {
                return $service;
            }
        }

        return $this->services->runningOn($today)[0] ?? null;
    }

    /** The restaurant's wall-clock now. */
    public function now(): \DateTimeImmutable
    {
        return $this->availability->now();
    }

    /** @return list<Room> */
    public function rooms(): array
    {
        return $this->rooms->ordered();
    }
}
