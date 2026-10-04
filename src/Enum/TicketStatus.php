<?php

namespace Base\Restaurant\Enum;

/**
 * A ticket's way through the kitchen, whatever its channel:
 *
 *     new ─▶ accepted ─▶ preparing ─▶ ready ─▶ served
 *      │         └──────────┴──────────┴─▶ cancelled
 *      └─▶ refused | cancelled
 *
 * A round sent from a table is accepted at once (the guests are seated);
 * a platform's order waits for the pass's answer, within the platform's delay.
 */
enum TicketStatus: string
{
    case NEW = 'new';
    case ACCEPTED = 'accepted';
    case PREPARING = 'preparing';
    case READY = 'ready';
    /** Brought to the table, handed to the courier or the customer */
    case SERVED = 'served';
    case REFUSED = 'refused';
    case CANCELLED = 'cancelled';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::NEW => [self::ACCEPTED, self::REFUSED, self::CANCELLED],
            self::ACCEPTED => [self::PREPARING, self::READY, self::CANCELLED],
            self::PREPARING => [self::READY, self::CANCELLED],
            self::READY => [self::SERVED, self::CANCELLED],
            default => [],
        };
    }

    public function canBecome(self $to): bool
    {
        return \in_array($to, $this->next(), true);
    }

    /** The one step forward the pass's main button takes; null at the end. */
    public function forward(): ?self
    {
        return match ($this) {
            self::NEW => self::ACCEPTED,
            self::ACCEPTED => self::PREPARING,
            self::PREPARING => self::READY,
            self::READY => self::SERVED,
            default => null,
        };
    }

    public function isFinal(): bool
    {
        return [] === $this->next();
    }

    /** Still on the pass: not served, refused nor cancelled. */
    public function isOpen(): bool
    {
        return !$this->isFinal();
    }

    /** The pass's column. */
    public function column(): ?string
    {
        return match ($this) {
            self::NEW => 'new',
            self::ACCEPTED, self::PREPARING => 'kitchen',
            self::READY => 'ready',
            self::SERVED => 'served',
            default => null,
        };
    }

    public function trans(): string
    {
        return 'ticket.status.'.$this->value;
    }
}
