<?php

namespace Base\Restaurant\Entity;

use Base\Restaurant\Model\Instant;
use Base\Restaurant\Enum\ReservationStatus;
use Base\Restaurant\Repository\ReservationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A table booked: when (the restaurant's wall-clock time: stored and read
 * as such, whatever the visitor's time zone), for how many, who, what they
 * asked for, its tables, where it came from - the site, the phone, a walk-in,
 * a platform (its reference there). The guest manages it through its token
 * (/reservation/{token}): see it, move it, cancel it, add it to a calendar.
 */
#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'restaurant_reservation')]
#[ORM\Index(name: 'restaurant_reservation_starts', columns: ['startsAt'])]
#[ORM\UniqueConstraint(name: 'restaurant_reservation_token', columns: ['token'])]
#[ORM\HasLifecycleCallbacks]
class Reservation
{
    public const SOURCE_SITE = 'site';
    public const SOURCE_PHONE = 'phone';
    public const SOURCE_WALK_IN = 'walk_in';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MealService::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MealService $service = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(type: 'integer')]
    private int $covers = 2;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $allergies = null;

    #[ORM\Column(length: 16, enumType: ReservationStatus::class)]
    private ReservationStatus $status = ReservationStatus::REQUESTED;

    /** @var Collection<int, Table> */
    #[ORM\ManyToMany(targetEntity: Table::class)]
    #[ORM\JoinTable(name: 'restaurant_reservation_table')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $tables;

    /** site, phone, walk_in, or the platform's name (thefork, zenchef...) */
    #[ORM\Column(length: 40)]
    private string $source = self::SOURCE_SITE;

    /** Its reference on the platform it came from, or was copied to */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $externalRef = null;

    #[ORM\Column(length: 43)]
    private string $token;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?\DateTimeImmutable $startsAt = null, int $covers = 2, string $name = '')
    {
        $this->startsAt = $startsAt ?? new \DateTimeImmutable('today 20:00');
        $this->endsAt = $this->startsAt->modify('+90 minutes');
        $this->covers = max(1, $covers);
        $this->name = $name;
        $this->tables = new ArrayCollection();
        $this->token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $this->createdAt = $this->updatedAt = Instant::now();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = Instant::now();
    }

    public function __toString(): string
    {
        return sprintf('%s · %d · %s', $this->startsAt->format('d/m H:i'), $this->covers, $this->name);
    }

    public function getId(): ?int { return $this->id; }
    public function getService(): ?MealService { return $this->service; }
    public function setService(?MealService $service): self { $this->service = $service; return $this; }

    /** The wall-clock time in the restaurant's zone (the zone PHP happens to hydrate it in is meaningless). */
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function getEndsAt(): \DateTimeImmutable { return $this->endsAt; }

    /** When, and for how long (minutes). */
    public function schedule(\DateTimeImmutable $startsAt, int $minutes): self
    {
        $this->startsAt = $startsAt;
        $this->endsAt = $startsAt->modify(sprintf('+%d minutes', max(15, $minutes)));

        return $this;
    }

    public function setStartsAt(\DateTimeImmutable $startsAt): self
    {
        $minutes = (int) round(($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp()) / 60);

        return $this->schedule($startsAt, $minutes > 0 ? $minutes : 90);
    }

    public function getDay(): string { return $this->startsAt->format('Y-m-d'); }
    public function getTime(): string { return $this->startsAt->format('H:i'); }
    public function getMinutes(): int { return (int) round(($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp()) / 60); }

    /** Both wall-clock "Y-m-d H:i", compared as such. */
    public function overlaps(string $from, string $until): bool
    {
        return $this->startsAt->format('Y-m-d H:i') < $until && $from < $this->endsAt->format('Y-m-d H:i');
    }

    public function getCovers(): int { return $this->covers; }
    public function setCovers(int $covers): self { $this->covers = max(1, $covers); return $this; }
    public function getName(): string { return $this->name; }
    public function setName(?string $name): self { $this->name = trim((string) $name); return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = trim((string) $phone) ?: null; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = trim((string) $email) ?: null; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = trim((string) $notes) ?: null; return $this; }
    public function getAllergies(): ?string { return $this->allergies; }
    public function setAllergies(?string $allergies): self { $this->allergies = trim((string) $allergies) ?: null; return $this; }
    public function getStatus(): ReservationStatus { return $this->status; }

    /** Forced: the pass and the platforms go through ReservationStatus::canBecome() first. */
    public function setStatus(ReservationStatus|string $status): self
    {
        $this->status = \is_string($status) ? ReservationStatus::from($status) : $status;
        if (!$this->status->holds()) {
            // A table given back: nobody waits on it anymore.
            $this->tables->clear();
        }

        return $this;
    }

    public function getStatusName(): string { return $this->status->value; }
    public function setStatusName(?string $status): self { return $this->setStatus(ReservationStatus::tryFrom((string) $status) ?? $this->status); }

    public function holds(): bool { return $this->status->holds(); }

    /** @return Collection<int, Table> */
    public function getTables(): Collection { return $this->tables; }

    /** @param iterable<Table> $tables */
    public function assign(iterable $tables): self
    {
        $this->tables->clear();
        foreach ($tables as $table) {
            $this->tables->add($table);
        }

        return $this;
    }

    public function getTablesLabel(): string
    {
        return implode('+', array_map(fn (Table $t) => $t->getLabel(), $this->tables->toArray()));
    }

    public function sits(Table $table): bool
    {
        return $this->tables->contains($table);
    }

    public function getSource(): string { return $this->source; }
    public function setSource(string $source): self { $this->source = $source; return $this; }
    public function isFromPlatform(): bool { return !\in_array($this->source, [self::SOURCE_SITE, self::SOURCE_PHONE, self::SOURCE_WALK_IN], true); }
    public function getExternalRef(): ?string { return $this->externalRef; }
    public function setExternalRef(?string $externalRef): self { $this->externalRef = $externalRef ?: null; return $this; }
    public function getToken(): string { return $this->token; }
    public function getLocale(): ?string { return $this->locale; }
    public function setLocale(?string $locale): self { $this->locale = $locale ? substr($locale, 0, 8) : null; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return Instant::read($this->createdAt); }
    public function getUpdatedAt(): \DateTimeImmutable { return Instant::read($this->updatedAt); }
}
