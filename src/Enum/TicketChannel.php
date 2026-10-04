<?php

namespace Base\Restaurant\Enum;

/** Where a ticket comes from: a table's phones, the counter (to take away), a delivery platform (Ticket::$platform names which). */
enum TicketChannel: string
{
    case TABLE = 'table';
    case TAKEAWAY = 'takeaway';
    case PLATFORM = 'platform';

    public function trans(): string
    {
        return 'ticket.channel.'.$this->value;
    }
}
