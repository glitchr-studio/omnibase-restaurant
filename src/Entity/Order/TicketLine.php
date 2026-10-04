<?php

namespace Base\Restaurant\Entity\Order;

use Base\Restaurant\Entity\Menu\Dish;
use Base\Restaurant\Enum\Station;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of a ticket: so many of a dish, its options ("bien cuit", "sans
 * oignons", "+ œuf"), a note, and the station that makes it. Its name and
 * price are copied when it is sent: the menu may change, the ticket does not.
 */
#[ORM\Entity]
#[ORM\Table(name: 'restaurant_ticket_line')]
class TicketLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $ticket = null;

    #[ORM\ManyToOne(targetEntity: Dish::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Dish $dish = null;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(type: 'integer')]
    private int $quantity = 1;

    /** Cents, VAT included, options included */
    #[ORM\Column(type: 'integer')]
    private int $unitPrice = 0;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $options = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 8, enumType: Station::class)]
    private Station $station = Station::HOT;

    public function __construct(string $name, int $quantity = 1, int $unitPrice = 0, Station $station = Station::HOT)
    {
        $this->name = mb_substr(trim($name), 0, 160);
        $this->quantity = max(1, $quantity);
        $this->unitPrice = max(0, $unitPrice);
        $this->station = $station;
    }

    public static function of(Dish $dish, int $quantity, int $unitPrice): self
    {
        $line = new self((string) $dish->getTitle(), $quantity, $unitPrice, $dish->getStation());
        $line->dish = $dish;

        return $line;
    }

    public function getId(): ?int { return $this->id; }
    public function getTicket(): ?Ticket { return $this->ticket; }
    public function setTicket(?Ticket $ticket): self { $this->ticket = $ticket; return $this; }
    public function getDish(): ?Dish { return $this->dish; }
    public function getName(): string { return $this->name; }
    public function getQuantity(): int { return $this->quantity; }
    public function getUnitPrice(): int { return $this->unitPrice; }
    public function getTotal(): int { return $this->unitPrice * $this->quantity; }
    /** @return list<string> */
    public function getOptions(): array { return $this->options; }
    /** @param list<string> $options */
    public function setOptions(array $options): self { $this->options = array_values(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 80), $options))); return $this; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): self { $this->note = $note ? mb_substr(trim($note), 0, 255) : null; return $this; }
    public function getStation(): Station { return $this->station; }
    public function setStation(Station $station): self { $this->station = $station; return $this; }
}
