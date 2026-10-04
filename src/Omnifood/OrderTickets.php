<?php

namespace Base\Restaurant\Omnifood;

use Base\Restaurant\Entity\Menu\Dish;
use Base\Restaurant\Entity\Order\Ticket;
use Base\Restaurant\Entity\Order\TicketLine;
use Base\Restaurant\Enum\Station;
use Base\Restaurant\Enum\TicketChannel;
use Base\Restaurant\Enum\TicketStatus;
use Omnifood\Model\Modifier;
use Omnifood\Model\Order;
use Omnifood\Model\OrderStatus;

/**
 * A platform's order (Omnifood\Model\Order) made a ticket: its reference
 * and short number, who ordered, the note, what was paid, when it was
 * placed and is collected, until when it may be accepted (the platform's
 * delay), and its lines - each tied to the menu's dish when the platform
 * carries the restaurant's reference back (the dish's station then),
 * its modifiers as the line's options. Nothing is read nor saved here.
 */
final class OrderTickets
{
    /**
     * @param int|null                $acceptanceDelay seconds the platform waits for an answer (Capabilities::$acceptanceDelay)
     * @param callable(string): ?Dish $dishFor         the menu's dish for a POS reference
     */
    public static function make(string $platform, Order $order, ?int $acceptanceDelay = null, ?callable $dishFor = null): Ticket
    {
        $ticket = (new Ticket(TicketChannel::PLATFORM, $platform))->setExternalRef($order->reference);
        $ticket->setDisplayId($order->displayId)->setCustomerName($order->customer?->name)
            ->setNote(trim(implode("\n", array_filter([$order->note, false === $order->cutlery ? '— sans couverts' : null]))) ?: null)
            ->setCurrency($order->total?->currency ?? 'EUR')
            ->setPaid($order->total?->amount)
            ->setPickupAt($order->pickupAt);
        if ($order->placedAt) {
            $ticket->setCreatedAt($order->placedAt);
        }
        if ($acceptanceDelay && OrderStatus::NEW === $order->status) {
            $ticket->setAcceptBy(($order->placedAt ?? new \DateTimeImmutable())->modify(sprintf('+%d seconds', $acceptanceDelay)));
        }
        foreach ($order->lines as $line) {
            $dish = $line->posRef && $dishFor ? $dishFor($line->posRef) : null;
            $unit = $line->unitPrice?->amount ?? ($line->total ? intdiv($line->total->amount, max(1, $line->quantity)) : 0);
            $ticketLine = $dish ? TicketLine::of($dish, $line->quantity, $unit) : new TicketLine($line->name, $line->quantity, $unit, Station::HOT);
            $ticket->addLine($ticketLine->setOptions(self::options($line->modifiers))->setNote($line->note));
        }
        $status = self::status($order->status);
        if ($status && TicketStatus::NEW !== $status) {
            $ticket->force($status);
        }

        return $ticket;
    }

    public static function status(OrderStatus $status): ?TicketStatus
    {
        return match ($status) {
            OrderStatus::NEW => TicketStatus::NEW,
            OrderStatus::ACCEPTED => TicketStatus::ACCEPTED,
            OrderStatus::READY => TicketStatus::READY,
            OrderStatus::PICKED_UP, OrderStatus::DELIVERED => TicketStatus::SERVED,
            OrderStatus::DENIED => TicketStatus::REFUSED,
            OrderStatus::CANCELLED => TicketStatus::CANCELLED,
            OrderStatus::UNKNOWN => null,
        };
    }

    /**
     * @param list<Modifier> $modifiers
     *
     * @return list<string> "2 × Œuf", nested choices indented under theirs
     */
    public static function options(array $modifiers, string $prefix = ''): array
    {
        $options = [];
        foreach ($modifiers as $modifier) {
            $options[] = $prefix.($modifier->quantity > 1 ? $modifier->quantity.' × ' : '').$modifier->name;
            array_push($options, ...self::options($modifier->modifiers, $prefix.'› '));
        }

        return $options;
    }
}
