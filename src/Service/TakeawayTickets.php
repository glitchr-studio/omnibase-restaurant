<?php

namespace Base\Restaurant\Service;

use Base\Database\Type\Utc;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Pickup;
use Base\Marketplace\Enum\PickupMode;
use Base\Marketplace\Enum\PickupStatus;
use Base\Marketplace\Service\Pickups;
use Base\Restaurant\Entity\Menu\Dish;
use Base\Restaurant\Entity\Order\Ticket;
use Base\Restaurant\Entity\Order\TicketLine;
use Base\Restaurant\Entity\Product\TakeHome;
use Base\Restaurant\Enum\Station;
use Base\Restaurant\Enum\TicketChannel;
use Base\Restaurant\Enum\TicketStatus;
use Base\Restaurant\Event\TicketMovedEvent;
use Base\Restaurant\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The shop's orders on the pass. A paid order with a hand-over
 * (omnibase/marketplace's Pickup, received) that holds the restaurant's
 * products - take-home dishes, dishes of the menu - becomes a ticket of the
 * "takeaway" channel, to accept and prepare like any other; and as the
 * ticket moves, so does the hand-over the customer follows on /suivi/{token}:
 * accepted, in preparation, ready, then handed over - or on its way, for a
 * delivery or a parcel.
 */
class TakeawayTickets
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TicketRepository $tickets,
        private readonly Pickups $pickups,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function of(Order|string $order): ?Ticket
    {
        $reference = $order instanceof Order ? (string) $order->getReference() : $order;

        return '' === $reference ? null : $this->tickets->findOneBy(['channel' => TicketChannel::TAKEAWAY, 'externalRef' => $reference]);
    }

    /** The ticket of a received hand-over - made once; null when the order holds nothing of the kitchen's. */
    public function fromPickup(Pickup $pickup): ?Ticket
    {
        $order = $pickup->getOrder();
        if ($existing = $this->of($order)) {
            return $existing;
        }

        // The mode in the place of a platform's name: the pass says "retrait", "livraison", "colis".
        $ticket = new Ticket(TicketChannel::TAKEAWAY, $pickup->getMode()->value);
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            if (!$product instanceof TakeHome && !$product instanceof Dish) {
                continue; // a delivery charge, something of another shop
            }
            $quantity = max(1, (int) $item->getQuantity());
            $unit = intdiv((int) $item->getNetPrice(), $quantity);
            $line = $product instanceof Dish
                ? TicketLine::of($product, $quantity, $unit)
                : new TicketLine((string) $product->getTitle(), $quantity, $unit, Station::COLD);
            $line->setOptions(array_map(fn (array $option) => (string) ($option['label'] ?? ''), $item->getOptions()));
            $ticket->addLine($line);
        }
        if ($ticket->getLines()->isEmpty()) {
            return null;
        }

        $ticket->setExternalRef((string) $order->getReference())
            ->setDisplayId((string) $order->getReference())
            ->setCustomerName($pickup->getContactName() ?? $pickup->getEmail())
            ->setNote($pickup->getNote())
            ->setCurrency((string) $order->getCurrency())
            ->setPaid((int) $order->getNetPrice())
            ->setPickupAt($this->moment($pickup));
        $this->entityManager->persist($ticket);
        $this->entityManager->flush();
        $this->dispatcher->dispatch(new TicketMovedEvent($ticket, null));

        return $ticket;
    }

    /** A take-away ticket moved on the pass: its hand-over follows. */
    public function follow(Ticket $ticket): void
    {
        if (TicketChannel::TAKEAWAY !== $ticket->getChannel() || !$ticket->getExternalRef()) {
            return;
        }
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['reference' => $ticket->getExternalRef()]);
        $pickup = $order ? $this->pickups->of($order) : null;
        if (!$pickup) {
            return;
        }
        $status = match ($ticket->getStatus()) {
            TicketStatus::ACCEPTED => PickupStatus::ACCEPTED,
            TicketStatus::PREPARING => PickupStatus::PREPARING,
            TicketStatus::READY => PickupStatus::READY,
            // Handed to the customer; to the driver or the carrier, it is on its way.
            TicketStatus::SERVED => PickupMode::PICKUP === $pickup->getMode() ? PickupStatus::DONE : PickupStatus::OUT_FOR_DELIVERY,
            TicketStatus::REFUSED => PickupStatus::REFUSED,
            TicketStatus::CANCELLED => PickupStatus::CANCELLED,
            default => null,
        };
        if ($status) {
            if (PickupStatus::READY === $status && !$pickup->getReadyAt()) {
                $pickup->setReadyAt($this->pickups->now());
            }
            $this->pickups->move($pickup, $status);
        }
    }

    /** The start of its slot on its day, on the shop's clock, as a moment. */
    private function moment(Pickup $pickup): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{2}:\d{2})/', (string) $pickup->getSlot(), $m)) {
            return null;
        }

        return Utc::from(new \DateTimeImmutable($pickup->getDay()->format('Y-m-d').' '.$m[1], $this->pickups->now()->getTimezone()));
    }
}
