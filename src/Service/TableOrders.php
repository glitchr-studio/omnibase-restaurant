<?php

namespace Base\Restaurant\Service;

use Base\Marketplace\Service\CartException;
use Base\Marketplace\Service\Pricing;
use Base\Marketplace\Service\ProductOptions;
use Base\Restaurant\Entity\Menu\Dish;
use Base\Restaurant\Entity\Order\Session;
use Base\Restaurant\Entity\Order\Ticket;
use Base\Restaurant\Entity\Order\TicketLine;
use Base\Restaurant\Entity\Table;
use Base\Restaurant\Enum\TicketChannel;
use Base\Restaurant\Enum\TicketStatus;
use Base\Restaurant\Event\TicketMovedEvent;
use Base\Restaurant\Exception\RestaurantException;
use Base\Restaurant\Model\Round;
use Base\Restaurant\Repository\DishRepository;
use Base\Restaurant\Repository\SessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ordering at the table, as in Japan: the guests scan their table's code,
 * which opens (or joins) the table's bill - no account, no app - and send
 * their rounds from their phones, call the waiter, ask for the bill. Each
 * round is a ticket on the pass, waiting to be accepted there. The prices
 * are the menu's, VAT included (omnibase/marketplace's Pricing), copied on
 * the ticket when it is sent.
 */
class TableOrders
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SessionRepository $sessions,
        private readonly DishRepository $dishes,
        private readonly Pricing $pricing,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ProductOptions $options,
        #[Autowire('%restaurant.table.max_lines%')] private readonly int $maxLines = 40,
        #[Autowire('%restaurant.table.max_quantity%')] private readonly int $maxQuantity = 20,
    ) {
    }

    /** The table's open bill; a new one when $open and there is none. */
    public function session(Table $table, bool $open = true): ?Session
    {
        $session = $this->sessions->openAt($table);
        if (!$session && $open) {
            $session = new Session($table);
            $this->entityManager->persist($session);
            $this->entityManager->flush();
        }

        return $session;
    }

    /**
     * A round sent: checked line by line against the menu (on sale, in
     * stock, orderable at the table, sensible quantities), priced, saved, on the pass.
     *
     * @throws RestaurantException with a key of the "restaurant" translations (table.error.*)
     */
    public function send(Session $session, Round $round, ?string $locale = null): Ticket
    {
        $table = $session->getTable();
        if (!$table->isActive() || !$table->isOrdering()) {
            throw new RestaurantException('table.error.closed');
        }
        if (!$session->isOpen() || \Base\Restaurant\Enum\SessionStatus::OPEN !== $session->getStatus()) {
            throw new RestaurantException('table.error.bill_asked');
        }
        if (!$round->lines || \count($round->lines) > $this->maxLines) {
            throw new RestaurantException('table.error.empty');
        }

        $ticket = new Ticket(TicketChannel::TABLE);
        $ticket->setNote($round->note);
        foreach ($round->lines as $line) {
            $dish = $this->dishes->find($line->dish);
            if (!$dish instanceof Dish || !$dish->isAvailable() || !$dish->isTableOrder()) {
                throw new RestaurantException('table.error.unavailable');
            }
            if ($line->quantity < 1 || $line->quantity > $this->maxQuantity) {
                throw new RestaurantException('table.error.quantity');
            }
            // The dish's options (a cooking, extras): checked - a required choice missing, one too many -
            // and their surcharge, with the dish's VAT, in the line's price.
            try {
                $selection = $this->options->select($dish, $line->optionIds());
            } catch (CartException $e) {
                throw new RestaurantException('@marketplace.'.$e->getMessage(), $e->getParameters(), $e);
            }
            $price = $this->pricing->priceWithVat($dish) + (int) round($selection->surcharge() * (1 + $this->pricing->vatRateFor($dish)));
            $ticketLine = TicketLine::of($dish, $line->quantity, $price)
                ->setOptions([...$selection->labels($locale), ...$line->optionWords()])->setNote($line->note);
            $ticket->addLine($ticketLine);
            $ticket->setCurrency((string) $dish->getCurrency());
        }
        if ($round->covers) {
            $session->setCovers($round->covers);
        }
        $session->addTicket($ticket);
        $this->entityManager->persist($ticket);
        $this->entityManager->flush();
        $this->dispatcher->dispatch(new TicketMovedEvent($ticket, null));

        return $ticket;
    }

    public function callWaiter(Session $session): void
    {
        $session->callWaiter();
        $this->entityManager->flush();
    }

    public function requestBill(Session $session): void
    {
        $session->requestBill();
        $this->entityManager->flush();
    }

    /**
     * What a guest's phone shows: the table's rounds and where each stands, the total.
     *
     * @return array<string, mixed>
     */
    public function state(Session $session): array
    {
        $tickets = [];
        foreach ($session->getTickets() as $ticket) {
            $tickets[] = [
                'id' => $ticket->getId(),
                'round' => $ticket->getRound(),
                'status' => $ticket->getStatus()->value,
                'lines' => array_map(fn (TicketLine $l) => ['name' => $l->getName(), 'quantity' => $l->getQuantity(), 'total' => $l->getTotal(), 'options' => $l->getOptions()], $ticket->getLines()->toArray()),
                'total' => $ticket->getTotal(),
            ];
        }

        return [
            'session' => $session->getId(),
            'status' => $session->getStatus()->value,
            'ordering' => $session->getTable()->isOrdering() && \Base\Restaurant\Enum\SessionStatus::OPEN === $session->getStatus(),
            'called' => null !== $session->getWaiterCalledAt(),
            'tickets' => $tickets,
            'total' => $session->getTotal(),
            'currency' => $session->getCurrency(),
        ];
    }

    /** @return list<array{status: TicketStatus, count: int}> */
    public function progress(Session $session): array
    {
        $counts = [];
        foreach ($session->getTickets() as $ticket) {
            $counts[$ticket->getStatus()->value] = ($counts[$ticket->getStatus()->value] ?? 0) + 1;
        }

        return array_map(fn ($s, $c) => ['status' => TicketStatus::from($s), 'count' => $c], array_keys($counts), $counts);
    }
}
