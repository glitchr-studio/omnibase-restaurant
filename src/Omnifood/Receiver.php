<?php

namespace Base\Restaurant\Omnifood;

use Base\Restaurant\Entity\Order\Ticket;
use Base\Restaurant\Entity\PlatformEvent;
use Base\Restaurant\Entity\Reservation;
use Base\Restaurant\Enum\ReservationStatus as Booked;
use Base\Restaurant\Enum\TicketStatus;
use Base\Restaurant\Event\ReservationChangedEvent;
use Base\Restaurant\Event\TicketMovedEvent;
use Base\Restaurant\Repository\DishRepository;
use Base\Restaurant\Repository\ReservationRepository;
use Base\Restaurant\Repository\TicketRepository;
use Base\Restaurant\Service\Availability;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Omnifood\Exception\NotSupportedException;
use Omnifood\Model\Notification;
use Omnifood\Model\NotificationSubject;
use Omnifood\Model\Order;
use Omnifood\Model\OrderStatus;
use Omnifood\Model\Reservation as Booking;
use Omnifood\Model\ReservationStatus;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A platform's webhook, made into the restaurant's own: an order becomes a
 * ticket on the pass (channel "platform", its reference there), a
 * cancellation cancels it; a reservation joins the book (source: the
 * platform), at the tables that fit. Each event once: the platforms retry,
 * Notification::$id is kept (PlatformEvent).
 */
class Receiver
{
    public function __construct(
        private readonly Platforms $platforms,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $doctrine,
        private readonly TicketRepository $tickets,
        private readonly ReservationRepository $reservations,
        private readonly DishRepository $dishes,
        private readonly Availability $availability,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Checks the call (Omnifood\Exception\InvalidSignatureException when it
     * does not hold) and handles it. Null: nothing to do (an event already
     * seen, a subject the restaurant does not keep).
     *
     * @param array<string, string|list<string>> $headers
     */
    public function receive(string $name, string $body, array $headers): Ticket|Reservation|null
    {
        $platform = $this->platforms->notifiable($name) ?? throw new NotSupportedException(\sprintf('The "%s" platform takes no webhooks.', $name));
        $notification = $platform->notify($body, $headers);
        if ($notification->id && $this->seen($name, $notification)) {
            return null;
        }
        // Handled first, remembered after: an event whose order could not be
        // read (the platform down, a key missing) is tried again at its retry.
        $handled = match ($notification->subject) {
            NotificationSubject::ORDER => $this->order($name, $notification),
            NotificationSubject::RESERVATION => $this->reservation($name, $notification),
            default => null,
        };
        if ($notification->id) {
            $this->remember($name, $notification);
        }

        return $handled;
    }

    /** An order as the platform has it now: a new ticket, or the one it already is, brought up to date. */
    public function ticket(string $name, Order $order): Ticket
    {
        $ticket = $this->tickets->findOneByExternal($name, $order->reference);
        $from = $ticket?->getStatus();
        if (!$ticket) {
            $ticket = OrderTickets::make($name, $order, $this->platforms->get($name)->capabilities()->acceptanceDelay, fn (string $ref) => $this->dishes->findOneBy(['slug' => $ref]) ?? (ctype_digit($ref) ? $this->dishes->find((int) $ref) : null));
            $this->entityManager->persist($ticket);
        }

        $status = OrderTickets::status($order->status);
        if ($status && $status !== $ticket->getStatus() && !$ticket->getStatus()->isFinal()) {
            $ticket->force($status);
        }
        $this->entityManager->flush();
        if ($from !== $ticket->getStatus()) {
            $this->dispatcher->dispatch(new TicketMovedEvent($ticket, $from, null, true));
        }

        return $ticket;
    }

    /** A reservation as the platform has it now: in the book, at the tables that fit (or none, for the staff to place). */
    public function booking(string $name, Booking $booking): Reservation
    {
        $reservation = $booking->reference ? $this->reservations->findOneByExternal($name, $booking->reference) : null;
        $previous = $reservation?->getStatus();
        $new = null === $reservation;
        $reservation ??= (new Reservation(null, $booking->covers))->setSource($name)->setExternalRef($booking->reference);
        $reservation->setName($booking->guest->name() ?: $name)->setEmail($booking->guest->email)->setPhone($booking->guest->phone)
            ->setNotes($booking->notes)->setAllergies($booking->allergies)->setCovers($booking->covers)
            ->setLocale($booking->guest->locale ? substr($booking->guest->locale, 0, 2) : null);
        $status = self::booked($booking->status);

        $when = $booking->date->format('Y-m-d H:i');
        if ($status->holds() && ($new || $reservation->getStartsAt()->format('Y-m-d H:i') !== $when || !$reservation->getTables()->count())) {
            $this->availability->book($reservation, $booking->date->format('Y-m-d'), $booking->date->format('H:i'), false);
        }
        $reservation->setStatus($status);
        $this->entityManager->persist($reservation);
        $this->entityManager->flush();
        $this->dispatcher->dispatch(new ReservationChangedEvent($reservation, $previous, true));

        return $reservation;
    }

    private function order(string $name, Notification $notification): ?Ticket
    {
        $order = $notification->order;
        if (!$order && $notification->reference) {
            $order = $this->platforms->orders($name)?->order($notification->reference);
        }
        if ($order) {
            return $this->ticket($name, $order);
        }
        // Only the news of a cancellation, about an order already here.
        $ticket = $notification->reference ? $this->tickets->findOneByExternal($name, $notification->reference) : null;
        if ($ticket && OrderStatus::CANCELLED === $notification->status && !$ticket->getStatus()->isFinal()) {
            $from = $ticket->getStatus();
            $ticket->force(TicketStatus::CANCELLED);
            $this->entityManager->flush();
            $this->dispatcher->dispatch(new TicketMovedEvent($ticket, $from, null, true));
        }

        return $ticket;
    }

    private function reservation(string $name, Notification $notification): ?Reservation
    {
        $booking = $notification->reservation;
        if (!$booking && $notification->reference) {
            $booking = $this->platforms->reservations($name)?->reservation($notification->reference);
        }

        return $booking ? $this->booking($name, $booking) : null;
    }

    private function seen(string $name, Notification $notification): bool
    {
        return null !== $this->entityManager->getRepository(PlatformEvent::class)->findOneBy(['platform' => $name, 'eventId' => mb_substr((string) $notification->id, 0, 190)]);
    }

    private function remember(string $name, Notification $notification): void
    {
        try {
            $this->entityManager->persist(new PlatformEvent($name, mb_substr((string) $notification->id, 0, 190), mb_substr($notification->event, 0, 80)));
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // The same event, at the same moment, from the platform's retry: the ticket's own
            // unique reference has kept it single.
            $this->doctrine->resetManager();
        }
    }

    private static function booked(ReservationStatus $status): Booked
    {
        return match ($status) {
            ReservationStatus::REQUESTED => Booked::REQUESTED,
            ReservationStatus::CONFIRMED, ReservationStatus::ARRIVED, ReservationStatus::UNKNOWN => Booked::CONFIRMED,
            ReservationStatus::SEATED => Booked::SEATED,
            ReservationStatus::FINISHED => Booked::FINISHED,
            ReservationStatus::NO_SHOW => Booked::NO_SHOW,
            ReservationStatus::CANCELLED => Booked::CANCELLED,
            ReservationStatus::REFUSED => Booked::REFUSED,
        };
    }
}
