<?php

namespace Base\Restaurant\Enum;

/**
 * Where a reservation stands. Asked for (above the service's automatic
 * confirmation, or from a platform that waits for an answer), confirmed,
 * then the evening: seated, finished - or absent; cancelled by the guest or
 * the restaurant, refused. The first three hold their tables.
 */
enum ReservationStatus: string
{
    case REQUESTED = 'requested';
    case CONFIRMED = 'confirmed';
    case SEATED = 'seated';
    case FINISHED = 'finished';
    case NO_SHOW = 'no_show';
    case CANCELLED = 'cancelled';
    case REFUSED = 'refused';

    /** It holds its tables (and counts against the service's covers). */
    public function holds(): bool
    {
        return \in_array($this, [self::REQUESTED, self::CONFIRMED, self::SEATED], true);
    }

    public function isFinal(): bool
    {
        return !$this->holds();
    }

    /** What the pass may make of it. @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::REQUESTED => [self::CONFIRMED, self::REFUSED, self::CANCELLED],
            self::CONFIRMED => [self::SEATED, self::NO_SHOW, self::CANCELLED],
            self::SEATED => [self::FINISHED],
            default => [],
        };
    }

    public function canBecome(self $to): bool
    {
        return \in_array($to, $this->next(), true);
    }

    public function trans(): string
    {
        return 'reservation.status.'.$this->value;
    }
}
