<?php

namespace Base\Restaurant\EventListener;

use Base\Marketplace\Enum\PickupStatus;
use Base\Marketplace\Event\PickupChangedEvent;
use Base\Restaurant\Event\TicketMovedEvent;
use Base\Restaurant\Service\TakeawayTickets;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The shop's orders and the pass, both ways: a hand-over received (its order
 * is paid) puts a ticket on the pass; the ticket moved there moves the
 * hand-over its customer follows.
 */
final class TakeawayListener
{
    public function __construct(private readonly TakeawayTickets $tickets)
    {
    }

    #[AsEventListener]
    public function onPickup(PickupChangedEvent $event): void
    {
        if (PickupStatus::RECEIVED === $event->pickup->getStatus()) {
            $this->tickets->fromPickup($event->pickup);
        }
    }

    #[AsEventListener]
    public function onTicket(TicketMovedEvent $event): void
    {
        if (null !== $event->from) {
            $this->tickets->follow($event->ticket);
        }
    }
}
