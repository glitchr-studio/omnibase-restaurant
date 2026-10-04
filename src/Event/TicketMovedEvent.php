<?php

namespace Base\Restaurant\Event;

use Base\Restaurant\Entity\Order\Ticket;
use Base\Restaurant\Enum\TicketStatus;

/**
 * A ticket arrived (from null) or moved along on the pass. The Omnifood
 * bridge tells the platform of a platform's order (accepted, denied, ready).
 */
final class TicketMovedEvent
{
    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?TicketStatus $from = null,
        /** Why a refusal or a cancellation: an Omnifood DenyReason value */
        public readonly ?string $reason = null,
        public readonly bool $fromPlatform = false,
    ) {
    }
}
