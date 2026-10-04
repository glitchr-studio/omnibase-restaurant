<?php

namespace Base\Restaurant\Entity\Order;

use Base\Restaurant\Model\Instant;
use Base\Restaurant\Entity\Table;
use Base\Restaurant\Enum\Station;
use Base\Restaurant\Enum\TicketChannel;
use Base\Restaurant\Enum\TicketStatus;
use Base\Restaurant\Exception\TransitionException;
use Base\Restaurant\Repository\TicketRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the kitchen makes, one ticket at a time, whatever it comes from: a
 * round sent from a table (its Session), an order to take away, a delivery
 * platform's order (Ticket::$platform, its reference there). Independent of
 * omnibase/marketplace's orders and their states: a ticket is the kitchen's
 * paper, it moves along TicketStatus.
 */
#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'restaurant_ticket')]
#[ORM\Index(name: 'restaurant_ticket_status', columns: ['status'])]
#[ORM\UniqueConstraint(name: 'restaurant_ticket_external', columns: ['platform', 'externalRef'])]
#[ORM\HasLifecycleCallbacks]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Session::class, inversedBy: 'tickets')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Session $session = null;

    #[ORM\ManyToOne(targetEntity: Table::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Table $table = null;

    #[ORM\Column(length: 12, enumType: TicketChannel::class)]
    private TicketChannel $channel;

    /** The platform's configured name (ubereats, deliveroo...) for a platform's order */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $platform = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $externalRef = null;

    /** The short number said at the counter ("A1B2C", "#42") */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $displayId = null;

    #[ORM\Column(length: 12, enumType: TicketStatus::class)]
    private TicketStatus $status = TicketStatus::NEW;

    /** Its round at the table: 1, 2, 3... */
    #[ORM\Column(type: 'integer')]
    private int $round = 1;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $customerName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    /** @var Collection<int, TicketLine> */
    #[ORM\OneToMany(targetEntity: TicketLine::class, mappedBy: 'ticket', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    /** What the platform says the customer paid, when it says: the lines' total otherwise */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $paid = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** A platform's order not answered by then is refused by the platform */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptBy = null;

    /** When the courier or the customer comes for it */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $pickupAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readyAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $servedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(TicketChannel $channel = TicketChannel::TABLE, ?string $platform = null)
    {
        $this->channel = $channel;
        $this->platform = $platform;
        $this->lines = new ArrayCollection();
        $this->createdAt = $this->updatedAt = Instant::now();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = Instant::now();
    }

    public function __toString(): string
    {
        return $this->getLabel();
    }

    /** "Table 12 · 2", "Uber Eats #A1B2C", "À emporter #7" */
    public function getLabel(): string
    {
        return match ($this->channel) {
            TicketChannel::TABLE => sprintf('%s · %d', $this->table?->getLabel() ?? '?', $this->round),
            TicketChannel::PLATFORM => sprintf('%s #%s', $this->platform, $this->displayId ?? $this->externalRef ?? $this->id),
            TicketChannel::TAKEAWAY => sprintf('#%s', $this->displayId ?? $this->id),
        };
    }

    public function getId(): ?int { return $this->id; }
    public function getSession(): ?Session { return $this->session; }

    public function setSession(?Session $session): self
    {
        $this->session = $session;
        if ($session) {
            $this->table = $session->getTable();
        }

        return $this;
    }

    public function getTable(): ?Table { return $this->table; }
    public function setTable(?Table $table): self { $this->table = $table; return $this; }
    public function getChannel(): TicketChannel { return $this->channel; }
    public function getPlatform(): ?string { return $this->platform; }
    public function isFromPlatform(): bool { return TicketChannel::PLATFORM === $this->channel; }
    public function getExternalRef(): ?string { return $this->externalRef; }
    public function setExternalRef(?string $ref): self { $this->externalRef = $ref ?: null; return $this; }
    public function getDisplayId(): ?string { return $this->displayId; }
    public function setDisplayId(?string $displayId): self { $this->displayId = $displayId ?: null; return $this; }
    public function getStatus(): TicketStatus { return $this->status; }
    public function getRound(): int { return $this->round; }
    public function setRound(int $round): self { $this->round = max(1, $round); return $this; }
    public function getCustomerName(): ?string { return $this->customerName; }
    public function setCustomerName(?string $name): self { $this->customerName = $name ?: null; return $this; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): self { $this->note = trim((string) $note) ?: null; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): self { $this->currency = strtoupper($currency); return $this; }

    /**
     * Moves it along; a step TicketStatus does not allow is refused - two
     * people on the pass tapping the same ticket do not serve it twice.
     *
     * @throws TransitionException
     */
    public function moveTo(TicketStatus $to, ?\DateTimeImmutable $at = null): self
    {
        if (!$this->status->canBecome($to)) {
            throw new TransitionException(sprintf('A ticket %s cannot become %s.', $this->status->value, $to->value));
        }
        $at = Instant::from($at) ?? Instant::now();
        match ($to) {
            TicketStatus::ACCEPTED => $this->acceptedAt = $at,
            TicketStatus::READY => $this->readyAt = $at,
            TicketStatus::SERVED => $this->servedAt = $at,
            default => null,
        };
        if (TicketStatus::READY === $to && !$this->acceptedAt) {
            $this->acceptedAt = $at;
        }
        $this->status = $to;
        $this->touch();

        return $this;
    }

    /** The one step forward. @throws TransitionException at the end */
    public function advance(): self
    {
        return $this->moveTo($this->status->forward() ?? throw new TransitionException(sprintf('A ticket %s goes no further.', $this->status->value)));
    }

    /** As the platform says it now is, without asking whether the pass would have gone that way. */
    public function force(TicketStatus $status): self
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    /** @return Collection<int, TicketLine> */
    public function getLines(): Collection { return $this->lines; }

    /** @return list<TicketLine> */
    public function getLinesFor(?Station $station): array
    {
        return array_values($this->lines->filter(fn (TicketLine $l) => null === $station || $l->getStation() === $station)->toArray());
    }

    public function addLine(TicketLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setTicket($this);
        }

        return $this;
    }

    /** @return list<Station> the stations it needs, in their order */
    public function getStations(): array
    {
        $needed = [];
        foreach ($this->lines as $line) {
            $needed[$line->getStation()->value] = true;
        }

        return array_values(array_filter(Station::cases(), fn (Station $s) => isset($needed[$s->value])));
    }

    public function count(): int
    {
        return array_sum(array_map(fn (TicketLine $l) => $l->getQuantity(), $this->lines->toArray()));
    }

    /** Cents, VAT included: what the platform says was paid, else the lines. */
    public function getTotal(): int
    {
        return $this->paid ?? array_sum(array_map(fn (TicketLine $l) => $l->getTotal(), $this->lines->toArray()));
    }

    public function getPaid(): ?int { return $this->paid; }
    public function setPaid(?int $paid): self { $this->paid = $paid; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return Instant::read($this->createdAt); }
    public function setCreatedAt(\DateTimeInterface $at): self { $this->createdAt = Instant::from($at); return $this; }
    public function getAcceptBy(): ?\DateTimeImmutable { return Instant::read($this->acceptBy); }
    public function setAcceptBy(?\DateTimeInterface $at): self { $this->acceptBy = Instant::from($at); return $this; }
    public function getPickupAt(): ?\DateTimeImmutable { return Instant::read($this->pickupAt); }
    public function setPickupAt(?\DateTimeInterface $at): self { $this->pickupAt = Instant::from($at); return $this; }
    public function getAcceptedAt(): ?\DateTimeImmutable { return Instant::read($this->acceptedAt); }
    public function getReadyAt(): ?\DateTimeImmutable { return Instant::read($this->readyAt); }
    public function getServedAt(): ?\DateTimeImmutable { return Instant::read($this->servedAt); }
    public function getUpdatedAt(): \DateTimeImmutable { return Instant::read($this->updatedAt); }

    /** Seconds left to accept a platform's order; null when nothing is awaited. */
    public function secondsToAccept(?\DateTimeImmutable $now = null): ?int
    {
        if (TicketStatus::NEW !== $this->status || !$this->acceptBy) {
            return null;
        }

        return Instant::read($this->acceptBy)->getTimestamp() - ($now ?? Instant::now())->getTimestamp();
    }
}
