<?php

namespace Base\Restaurant\Omnifood;

use Base\Restaurant\Enum\ReservationStatus as Booked;
use Base\Restaurant\Enum\TicketStatus;
use Base\Restaurant\Event\DishAvailabilityEvent;
use Base\Restaurant\Event\ReservationChangedEvent;
use Base\Restaurant\Event\TicketMovedEvent;
use Omnifood\Model\DenyReason;
use Omnifood\Model\ReservationStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * What the pass does, told to the platforms: a platform's order accepted,
 * denied, ready or cancelled; a dish run out (or back) on every platform
 * whose menu it is on; a platform's reservation seated, finished, absent or
 * cancelled. A platform that fails is logged: the pass goes on (the
 * restaurant's tablet from the platform is the fallback).
 */
final class PlatformSubscriber
{
    /** Minutes a platform is told the kitchen needs, when it takes a time */
    public const PREPARATION = 15;

    public function __construct(private readonly Platforms $platforms, private readonly ?LoggerInterface $logger = null)
    {
    }

    #[AsEventListener]
    public function onTicket(TicketMovedEvent $event): void
    {
        $ticket = $event->ticket;
        $name = $ticket->getPlatform();
        $ref = $ticket->getExternalRef();
        if ($event->fromPlatform || !$ticket->isFromPlatform() || !$name || !$ref) {
            return;
        }
        $this->tell($name, 'order '.$ref.' '.$ticket->getStatus()->value, function () use ($name, $ref, $ticket, $event) {
            $orders = $this->platforms->orders($name);
            if (!$orders) {
                return;
            }
            $reason = DenyReason::tryFrom((string) $event->reason) ?? DenyReason::TOO_BUSY;
            match ($ticket->getStatus()) {
                TicketStatus::ACCEPTED => $orders->accept($ref, new \DateTimeImmutable(sprintf('+%d minutes', self::PREPARATION))),
                TicketStatus::READY => $orders->ready($ref),
                TicketStatus::REFUSED => $orders->deny($ref, $reason),
                TicketStatus::CANCELLED => TicketStatus::NEW === $event->from ? $orders->deny($ref, $reason) : $orders->cancel($ref, $reason),
                default => null,
            };
        });
    }

    #[AsEventListener]
    public function onDish(DishAvailabilityEvent $event): void
    {
        foreach ($this->platforms->having(\Omnifood\MenuInterface::class) as $name => $menu) {
            $this->tell($name, 'item '.$event->dish->getPosRef(), fn () => $menu->setAvailability($event->dish->getPosRef(), $event->available));
        }
    }

    #[AsEventListener]
    public function onReservation(ReservationChangedEvent $event): void
    {
        $reservation = $event->reservation;
        $ref = $reservation->getExternalRef();
        if ($event->fromPlatform || !$ref || !$reservation->isFromPlatform() || $event->previous === $reservation->getStatus()) {
            return;
        }
        $name = $reservation->getSource();
        $this->tell($name, 'reservation '.$ref, function () use ($name, $ref, $reservation) {
            $platform = $this->platforms->reservations($name);
            if (!$platform) {
                return;
            }
            match ($reservation->getStatus()) {
                Booked::CANCELLED, Booked::REFUSED => $platform->cancelReservation($ref),
                Booked::CONFIRMED => $platform->setStatus($ref, ReservationStatus::CONFIRMED),
                Booked::SEATED => $platform->setStatus($ref, ReservationStatus::SEATED),
                Booked::FINISHED => $platform->setStatus($ref, ReservationStatus::FINISHED),
                Booked::NO_SHOW => $platform->setStatus($ref, ReservationStatus::NO_SHOW),
                default => null,
            };
        });
    }

    private function tell(string $name, string $what, callable $call): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $this->logger?->warning(sprintf('restaurant: %s could not be told about %s: %s', $name, $what, $e->getMessage()), ['exception' => $e]);
        }
    }
}
