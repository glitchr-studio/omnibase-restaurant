<?php

namespace Base\Restaurant\Event;

use Base\Restaurant\Entity\Reservation;
use Base\Restaurant\Enum\ReservationStatus;

/**
 * A reservation taken, moved or moved along (confirmed, seated, cancelled...).
 * $previous is its status before; null for a new one. The Omnifood bridge
 * tells the platform it came from.
 */
final class ReservationChangedEvent
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly ?ReservationStatus $previous = null,
        /** Told by the platform itself: nothing to send back */
        public readonly bool $fromPlatform = false,
    ) {
    }
}
