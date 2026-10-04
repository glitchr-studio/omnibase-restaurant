<?php

namespace Base\Restaurant\Entity;

use Base\Restaurant\Enum\TableShape;
use Base\Restaurant\Repository\TableRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A table of a room: its label ("12", "C3"), how many it seats, its shape
 * and its place on the plan (centimetres from the room's top left corner,
 * its centre; degrees). Its token is what its QR code carries (/t/{token}):
 * regenerated, the old posters stop working.
 */
#[ORM\Entity(repositoryClass: TableRepository::class)]
#[ORM\Table(name: 'restaurant_table')]
#[ORM\UniqueConstraint(name: 'restaurant_table_token', columns: ['token'])]
class Table
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Room::class, inversedBy: 'tables')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Room $room = null;

    #[ORM\Column(length: 20)]
    private string $label;

    #[ORM\Column(type: 'integer')]
    private int $minCovers = 1;

    #[ORM\Column(type: 'integer')]
    private int $maxCovers = 2;

    #[ORM\Column(length: 16, enumType: TableShape::class)]
    private TableShape $shape = TableShape::SQUARE;

    #[ORM\Column(type: 'integer')]
    private int $x = 100;

    #[ORM\Column(type: 'integer')]
    private int $y = 100;

    #[ORM\Column(type: 'integer')]
    private int $width = 80;

    #[ORM\Column(type: 'integer')]
    private int $height = 80;

    #[ORM\Column(type: 'integer')]
    private int $rotation = 0;

    #[ORM\Column(length: 32)]
    private string $token;

    /** Taken into account for reservations and shown on the pass */
    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    /** Its guests may order from their phones (the pass can close a table to orders) */
    #[ORM\Column(type: 'boolean')]
    private bool $ordering = true;

    /** Not offered online: kept for walk-ins and the phone */
    #[ORM\Column(type: 'boolean')]
    private bool $bookable = true;

    #[ORM\Column(type: 'integer')]
    private int $position = 0;

    public function __construct(string $label = '', int $maxCovers = 2, TableShape $shape = TableShape::SQUARE)
    {
        $this->label = $label;
        $this->shape = $shape;
        $this->setCovers(1, $maxCovers);
        $this->token = self::newToken();
    }

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public function __toString(): string
    {
        return $this->label;
    }

    public function getId(): ?int { return $this->id; }
    public function getRoom(): ?Room { return $this->room; }
    public function setRoom(?Room $room): self { $this->room = $room; return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): self { $this->label = trim($label); return $this; }
    public function getMinCovers(): int { return $this->minCovers; }
    public function setMinCovers(int $minCovers): self { $this->minCovers = max(1, $minCovers); $this->maxCovers = max($this->maxCovers, $this->minCovers); return $this; }
    public function getMaxCovers(): int { return $this->maxCovers; }
    public function setMaxCovers(int $maxCovers): self { $this->maxCovers = max(1, $maxCovers); $this->minCovers = min($this->minCovers, $this->maxCovers); return $this; }

    public function setCovers(int $min, int $max): self
    {
        $this->maxCovers = max(1, $max);
        $this->minCovers = max(1, min($min, $this->maxCovers));

        return $this;
    }

    public function seats(int $covers): bool
    {
        return $covers >= 1 && $covers <= $this->maxCovers;
    }

    public function getShape(): TableShape { return $this->shape; }
    public function setShape(TableShape|string $shape): self { $this->shape = \is_string($shape) ? TableShape::from($shape) : $shape; return $this; }
    public function getShapeName(): string { return $this->shape->value; }
    public function setShapeName(?string $shape): self { $this->shape = TableShape::tryFrom((string) $shape) ?? TableShape::SQUARE; return $this; }
    public function getX(): int { return $this->x; }
    public function setX(int $x): self { $this->x = $x; return $this; }
    public function getY(): int { return $this->y; }
    public function setY(int $y): self { $this->y = $y; return $this; }
    public function getWidth(): int { return $this->width; }
    public function setWidth(int $width): self { $this->width = max(20, $width); return $this; }
    public function getHeight(): int { return $this->height; }
    public function setHeight(int $height): self { $this->height = max(20, $height); return $this; }
    public function getRotation(): int { return $this->rotation; }
    public function setRotation(int $rotation): self { $this->rotation = (($rotation % 360) + 360) % 360; return $this; }

    public function place(int $x, int $y, ?int $width = null, ?int $height = null, ?int $rotation = null): self
    {
        $this->x = $x;
        $this->y = $y;
        if (null !== $width) {
            $this->setWidth($width);
        }
        if (null !== $height) {
            $this->setHeight($height);
        }
        if (null !== $rotation) {
            $this->setRotation($rotation);
        }

        return $this;
    }

    public function getToken(): string { return $this->token; }

    /** A new code: the posters printed before stop working. */
    public function regenerateToken(): self
    {
        $this->token = self::newToken();

        return $this;
    }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }
    public function isOrdering(): bool { return $this->ordering; }
    public function setOrdering(bool $ordering): self { $this->ordering = $ordering; return $this; }
    public function isBookable(): bool { return $this->bookable; }
    public function setBookable(bool $bookable): self { $this->bookable = $bookable; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }
}
