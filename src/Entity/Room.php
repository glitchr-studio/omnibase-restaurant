<?php

namespace Base\Restaurant\Entity;

use Base\Restaurant\Repository\RoomRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A dining room (or a terrace): its size in centimetres, what is drawn in it
 * besides the tables - walls, the counter, the doors, the kitchen pass - and
 * its tables. Its layouts move the tables around and join them for a
 * service or a date (Layout).
 */
#[ORM\Entity(repositoryClass: RoomRepository::class)]
#[ORM\Table(name: 'restaurant_room')]
class Room
{
    /** What the decor may hold, besides the tables. */
    public const DECOR = ['wall', 'counter', 'door', 'window', 'kitchen', 'plant', 'label'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $name;

    /** Centimetres, left to right */
    #[ORM\Column(type: 'integer')]
    private int $width = 1200;

    /** Centimetres, top to bottom */
    #[ORM\Column(type: 'integer')]
    private int $height = 800;

    /** @var list<array{type: string, x: int, y: int, w: int, h: int, rotation?: int, label?: string}> */
    #[ORM\Column(type: 'json')]
    private array $decor = [];

    #[ORM\Column(type: 'integer')]
    private int $position = 0;

    /** @var Collection<int, Table> */
    #[ORM\OneToMany(targetEntity: Table::class, mappedBy: 'room', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $tables;

    /** @var Collection<int, Layout> */
    #[ORM\OneToMany(targetEntity: Layout::class, mappedBy: 'room', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $layouts;

    public function __construct(string $name = '', int $width = 1200, int $height = 800)
    {
        $this->name = $name;
        $this->setSize($width, $height);
        $this->tables = new ArrayCollection();
        $this->layouts = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getWidth(): int { return $this->width; }
    public function setWidth(int $width): self { $this->width = max(100, $width); return $this; }
    public function getHeight(): int { return $this->height; }
    public function setHeight(int $height): self { $this->height = max(100, $height); return $this; }
    public function setSize(int $width, int $height): self { return $this->setWidth($width)->setHeight($height); }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    /** @return list<array<string, mixed>> */
    public function getDecor(): array { return $this->decor; }

    /** Only what the editor knows how to draw is kept, in whole centimetres. */
    public function setDecor(array $decor): self
    {
        $this->decor = [];
        foreach ($decor as $item) {
            if (!\is_array($item) || !\in_array($item['type'] ?? null, self::DECOR, true)) {
                continue;
            }
            $clean = ['type' => $item['type']];
            foreach (['x', 'y', 'w', 'h', 'rotation'] as $key) {
                $clean[$key] = (int) round((float) ($item[$key] ?? 0));
            }
            $clean['w'] = max(1, $clean['w']);
            $clean['h'] = max(1, $clean['h']);
            if (isset($item['label']) && '' !== trim((string) $item['label'])) {
                $clean['label'] = mb_substr(trim((string) $item['label']), 0, 40);
            }
            $this->decor[] = $clean;
        }

        return $this;
    }

    /** @return Collection<int, Table> */
    public function getTables(): Collection { return $this->tables; }

    /** @return list<Table> */
    public function getActiveTables(): array
    {
        return array_values($this->tables->filter(fn (Table $t) => $t->isActive())->toArray());
    }

    public function addTable(Table $table): self
    {
        if (!$this->tables->contains($table)) {
            $this->tables->add($table);
            $table->setRoom($this);
        }

        return $this;
    }

    public function removeTable(Table $table): self
    {
        $this->tables->removeElement($table);

        return $this;
    }

    /** @return Collection<int, Layout> */
    public function getLayouts(): Collection { return $this->layouts; }

    public function addLayout(Layout $layout): self
    {
        if (!$this->layouts->contains($layout)) {
            $this->layouts->add($layout);
            $layout->setRoom($this);
        }

        return $this;
    }

    public function removeLayout(Layout $layout): self
    {
        $this->layouts->removeElement($layout);

        return $this;
    }

    public function getDefaultLayout(): ?Layout
    {
        foreach ($this->layouts as $layout) {
            if ($layout->isDefault()) {
                return $layout;
            }
        }

        return null;
    }

    public function getCapacity(): int
    {
        return array_sum(array_map(fn (Table $t) => $t->getMaxCovers(), $this->getActiveTables()));
    }
}
