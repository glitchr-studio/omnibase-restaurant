<?php

namespace Base\Restaurant\Enum;

/** A table's bill: open while the guests order, asked for, settled (at the till, or from a phone). */
enum SessionStatus: string
{
    case OPEN = 'open';
    case BILL = 'bill';
    case SETTLED = 'settled';

    public function isOpen(): bool
    {
        return self::SETTLED !== $this;
    }

    public function trans(): string
    {
        return 'session.status.'.$this->value;
    }
}
