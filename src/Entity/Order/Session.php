<?php

namespace Base\Restaurant\Entity\Order;

use Base\Database\Type\Utc;
use Base\Database\Type\UtcDateTimeImmutableType;
use Base\Restaurant\Entity\Reservation;
use Base\Restaurant\Entity\Table;
use Base\Restaurant\Enum\SessionStatus;
use Base\Restaurant\Enum\TicketStatus;
use Base\Restaurant\Repository\SessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A table's bill, from the first scan of its code to the till: the rounds
 * (Ticket) its guests send, a call for the waiter, the bill asked for, then
 * settled - at the till by default (cash, card), or from a phone where the
 * site allows it. One open at a time per table; the next guests open a new one.
 */
#[ORM\Entity(repositoryClass: SessionRepository::class)]
#[ORM\Table(name: 'restaurant_session')]
#[ORM\Index(name: 'restaurant_session_status', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
class Session
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Table::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Table $table;

    #[ORM\Column(length: 12, enumType: SessionStatus::class)]
    private SessionStatus $status = SessionStatus::OPEN;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $covers = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Reservation $reservation = null;

    /** @var Collection<int, Ticket> */
    #[ORM\OneToMany(targetEntity: Ticket::class, mappedBy: 'session', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $tickets;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $waiterCalledAt = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $billRequestedAt = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME, nullable: true)]
    private ?\DateTimeImmutable $settledAt = null;

    /** "cash", "card", "online"...: how the bill was paid */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $settledWith = null;

    /** The total settled, in cents, VAT included */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $settledAmount = null;

    #[ORM\Column(type: UtcDateTimeImmutableType::NAME)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Table $table, ?int $covers = null)
    {
        $this->table = $table;
        $this->covers = $covers;
        $this->tickets = new ArrayCollection();
        $this->openedAt = $this->updatedAt = Utc::now();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = Utc::now();
    }

    public function getId(): ?int { return $this->id; }
    public function getTable(): Table { return $this->table; }

    /** The guests moved to another table: the bill follows them. */
    public function moveTo(Table $table): self { $this->table = $table; $this->touch(); return $this; }

    public function getStatus(): SessionStatus { return $this->status; }
    public function isOpen(): bool { return $this->status->isOpen(); }
    public function getCovers(): ?int { return $this->covers; }
    public function setCovers(?int $covers): self { $this->covers = $covers > 0 ? $covers : null; return $this; }
    public function getReservation(): ?Reservation { return $this->reservation; }
    public function setReservation(?Reservation $reservation): self { $this->reservation = $reservation; return $this; }

    /** @return Collection<int, Ticket> */
    public function getTickets(): Collection { return $this->tickets; }

    public function addTicket(Ticket $ticket): self
    {
        if (!$this->tickets->contains($ticket)) {
            $this->tickets->add($ticket);
            $ticket->setSession($this);
            $ticket->setRound($this->tickets->count());
        }
        $this->touch();

        return $this;
    }

    /** @return list<Ticket> the rounds still counted on the bill (not refused nor cancelled) */
    public function getBilledTickets(): array
    {
        return array_values($this->tickets->filter(fn (Ticket $t) => !\in_array($t->getStatus(), [TicketStatus::REFUSED, TicketStatus::CANCELLED], true))->toArray());
    }

    /** Cents, VAT included. */
    public function getTotal(): int
    {
        return array_sum(array_map(fn (Ticket $t) => $t->getTotal(), $this->getBilledTickets()));
    }

    public function getCurrency(): string
    {
        $first = $this->tickets->first();

        return $first ? $first->getCurrency() : 'EUR';
    }

    /** Some round still on its way from the kitchen. */
    public function isWaiting(): bool
    {
        foreach ($this->tickets as $ticket) {
            if (\in_array($ticket->getStatus(), [TicketStatus::NEW, TicketStatus::ACCEPTED, TicketStatus::PREPARING], true)) {
                return true;
            }
        }

        return false;
    }

    public function hasReady(): bool
    {
        foreach ($this->tickets as $ticket) {
            if (TicketStatus::READY === $ticket->getStatus()) {
                return true;
            }
        }

        return false;
    }

    public function getOpenedAt(): \DateTimeImmutable { return $this->openedAt; }
    public function getWaiterCalledAt(): ?\DateTimeImmutable { return $this->waiterCalledAt; }
    public function callWaiter(): self { $this->waiterCalledAt = Utc::now(); $this->touch(); return $this; }
    public function answerCall(): self { $this->waiterCalledAt = null; $this->touch(); return $this; }
    public function getBillRequestedAt(): ?\DateTimeImmutable { return $this->billRequestedAt; }

    public function requestBill(): self
    {
        if (SessionStatus::OPEN === $this->status) {
            $this->status = SessionStatus::BILL;
            $this->billRequestedAt = Utc::now();
            $this->touch();
        }

        return $this;
    }

    /**
     * The bill is being settled: none of its rounds stays on the pass. What
     * was still open - waiting, on the stove, ready - is served: the guests
     * pay for it and leave. Settling never changes what is billed; a round
     * that was not made is cancelled on the pass before the bill is settled.
     *
     * @return list<Ticket> those it closed
     */
    public function closeTickets(?\DateTimeImmutable $at = null): array
    {
        $at = Utc::from($at) ?? Utc::now();
        $closed = [];
        foreach ($this->tickets as $ticket) {
            if ($ticket->getStatus()->isOpen()) {
                $closed[] = $ticket->close(TicketStatus::SERVED, $at);
            }
        }

        return $closed;
    }

    /** Paid: its open rounds closed (closeTickets()), the table is free for the next guests. */
    public function settle(string $with, ?int $amount = null): self
    {
        $amount ??= $this->getTotal();
        $this->closeTickets();
        $this->status = SessionStatus::SETTLED;
        $this->settledAt = Utc::now();
        $this->settledWith = $with;
        $this->settledAmount = $amount;
        $this->waiterCalledAt = null;
        $this->touch();

        return $this;
    }

    /** The marketplace order the bill is paid with, when it is paid from a phone (restaurant.table.pay_online). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $orderReference = null;

    public function getOrderReference(): ?string { return $this->orderReference; }
    public function setOrderReference(?string $reference): self { $this->orderReference = $reference ?: null; return $this; }

    public function getSettledAt(): ?\DateTimeImmutable { return $this->settledAt; }
    public function getSettledWith(): ?string { return $this->settledWith; }
    public function getSettledAmount(): ?int { return $this->settledAmount; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
