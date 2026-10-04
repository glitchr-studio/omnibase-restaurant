<?php

namespace Base\Restaurant\Entity;

use Base\Restaurant\Repository\LayoutRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named arrangement of a room - "Midi", "Soir", "Privatisation": where each
 * table stands, which tables are out, which are joined to seat a larger
 * party. A meal service names its layout; a layout may also take over on
 * given dates (a private party). The room's default layout serves when
 * neither says.
 */
#[ORM\Entity(repositoryClass: LayoutRepository::class)]
#[ORM\Table(name: 'restaurant_layout')]
class Layout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Room::class, inversedBy: 'layouts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Room $room = null;

    #[ORM\Column(length: 60)]
    private string $name;

    /** @var array<string, array{x: int, y: int, rotation: int, active: bool}> table id => its place in this layout */
    #[ORM\Column(type: 'json')]
    private array $positions = [];

    /** @var list<list<int>> groups of table ids pushed together */
    #[ORM\Column(type: 'json')]
    private array $joins = [];

    /** @var list<string> Y-m-d: the days this layout takes over, whatever the service */
    #[ORM\Column(type: 'json')]
    private array $dates = [];

    /** The column is not named `default`: a reserved word */
    #[ORM\Column(name: 'isDefault', type: 'boolean')]
    private bool $default = false;

    public function __construct(string $name = '', ?Room $room = null)
    {
        $this->name = $name;
        $room?->addLayout($this);
    }

    public function __toString(): string
    {
        return ($this->room ? $this->room->getName().' · ' : '').$this->name;
    }

    public function getId(): ?int { return $this->id; }
    public function getRoom(): ?Room { return $this->room; }
    public function setRoom(?Room $room): self { $this->room = $room; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function isDefault(): bool { return $this->default; }
    public function setDefault(bool $default): self { $this->default = $default; return $this; }

    /** @return array<string, array{x: int, y: int, rotation: int, active: bool}> */
    public function getPositions(): array { return $this->positions; }

    public function setPositions(array $positions): self
    {
        $this->positions = [];
        foreach ($positions as $id => $p) {
            if (!\is_array($p) || (int) $id <= 0) {
                continue;
            }
            $this->positions[(string) (int) $id] = [
                'x' => (int) round((float) ($p['x'] ?? 0)),
                'y' => (int) round((float) ($p['y'] ?? 0)),
                'rotation' => (((int) ($p['rotation'] ?? 0)) % 360 + 360) % 360,
                'active' => (bool) ($p['active'] ?? true),
            ];
        }

        return $this;
    }

    public function place(Table $table, int $x, int $y, int $rotation = 0, bool $active = true): self
    {
        $this->positions[(string) $table->getId()] = ['x' => $x, 'y' => $y, 'rotation' => $rotation, 'active' => $active];

        return $this;
    }

    /** @return array{x: int, y: int, rotation: int, active: bool} where the table stands here (its own place when this layout does not say) */
    public function positionOf(Table $table): array
    {
        return $this->positions[(string) $table->getId()] ?? ['x' => $table->getX(), 'y' => $table->getY(), 'rotation' => $table->getRotation(), 'active' => true];
    }

    public function uses(Table $table): bool
    {
        return $table->isActive() && $this->positionOf($table)['active'];
    }

    /** @return list<list<int>> */
    public function getJoins(): array { return $this->joins; }

    /** Each group: two tables or more, each table in one group only. */
    public function setJoins(array $joins): self
    {
        $seen = [];
        $this->joins = [];
        foreach ($joins as $group) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $group), fn (int $id) => $id > 0 && !isset($seen[$id]))));
            if (\count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                $seen[$id] = true;
            }
            sort($ids);
            $this->joins[] = $ids;
        }

        return $this;
    }

    public function join(Table ...$tables): self
    {
        return $this->setJoins([...$this->joins, array_map(fn (Table $t) => (int) $t->getId(), $tables)]);
    }

    /** @return list<string> */
    public function getDates(): array { return $this->dates; }

    /** @param list<string|\DateTimeInterface>|string $dates "Y-m-d" each, or one per line */
    public function setDates(array|string|null $dates): self
    {
        $list = \is_array($dates) ? $dates : preg_split('/[\s,;]+/', (string) $dates);
        $clean = [];
        foreach ($list as $date) {
            $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : trim((string) $date);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $clean[$date] = $date;
            }
        }
        sort($clean);
        $this->dates = array_values($clean);

        return $this;
    }

    public function getDatesText(): string { return implode("\n", $this->dates); }
    public function setDatesText(?string $text): self { return $this->setDates($text); }

    public function takesOver(\DateTimeInterface $day): bool
    {
        return \in_array($day->format('Y-m-d'), $this->dates, true);
    }
}
