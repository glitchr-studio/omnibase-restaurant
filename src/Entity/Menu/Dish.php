<?php

namespace Base\Restaurant\Entity\Menu;

use Base\Database\Attribute\DiscriminatorEntry;
use Base\Enum\Allergen;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Enum\ProductAvailability;
use Base\Restaurant\Enum\Diet;
use Base\Restaurant\Enum\Station;
use Base\Restaurant\Repository\DishRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A dish of the menu: an omnibase/marketplace product (its title and text,
 * its price before VAT and the store's VAT, its stock and availability), with
 * what a restaurant's menu says besides - its name in the kitchen's own
 * language, a drawing until there is a photo, the EU allergens
 * (Base\Enum\Allergen), the diets it suits, how hot it is, its section, and
 * the station that makes it (the pass filters by it).
 *
 * Out of stock (ProductAvailability::OUTOFSTOCK, "rupture" on the pass), it
 * stays on the menu, greyed, cannot be ordered at a table, and is suspended
 * on the delivery platforms.
 */
#[ORM\Entity(repositoryClass: DishRepository::class)]
#[DiscriminatorEntry(value: 'restaurant_dish')]
class Dish extends Product
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-bowl-food'];
    }

    /** "味噌ラーメン" */
    #[ORM\Column(length: 80, nullable: true)]
    protected ?string $nativeName = null;

    /** A drawing shown while there is no photo: a path under the site's public assets */
    #[ORM\Column(length: 160, nullable: true)]
    protected ?string $illustration = null;

    /** @var list<string> Base\Enum\Allergen values */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $allergens = [];

    /** @var list<string> Base\Restaurant\Enum\Diet values */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $diets = [];

    /** 0 to 3 chillies */
    #[ORM\Column(type: 'smallint', nullable: true)]
    protected ?int $spice = 0;

    /** Base\Restaurant\Enum\Station's value, a string: omnibase's uploads rehydrate raw values */
    #[ORM\Column(length: 8, nullable: true)]
    protected ?string $stationName = Station::HOT->value;

    #[ORM\ManyToOne(targetEntity: MenuSection::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?MenuSection $section = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $menuPosition = 0;

    /** Orderable from a table's phones (a dish for the counter only says no) */
    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $tableOrder = true;

    public function getNativeName(): ?string { return $this->nativeName; }
    public function setNativeName(?string $name): self { $this->nativeName = trim((string) $name) ?: null; return $this; }
    public function getIllustration(): ?string { return $this->illustration; }
    public function setIllustration(?string $illustration): self { $this->illustration = $illustration ?: null; return $this; }

    /** @return list<string> */
    public function getAllergens(): array { return array_map(fn (Allergen $a) => $a->value, Allergen::fromList($this->allergens ?? [])); }
    /** @param iterable<string|Allergen> $allergens */
    public function setAllergens(iterable $allergens): self { $this->allergens = array_map(fn (Allergen $a) => $a->value, Allergen::fromList($allergens)); return $this; }
    /** @return list<Allergen> */
    public function getAllergenCases(): array { return Allergen::fromList($this->allergens ?? []); }
    public function contains(Allergen|string $allergen): bool { return \in_array($allergen instanceof Allergen ? $allergen->value : $allergen, $this->allergens ?? [], true); }

    /** @return list<string> */
    public function getDiets(): array { return array_map(fn (Diet $d) => $d->value, Diet::fromList($this->diets ?? [])); }
    /** @param iterable<string|Diet> $diets */
    public function setDiets(iterable $diets): self { $this->diets = array_map(fn (Diet $d) => $d->value, Diet::fromList($diets)); return $this; }
    /** @return list<Diet> */
    public function getDietCases(): array { return Diet::fromList($this->diets ?? []); }
    public function suits(Diet|string $diet): bool { return \in_array($diet instanceof Diet ? $diet->value : $diet, $this->diets ?? [], true); }

    public function getSpice(): int { return (int) $this->spice; }
    public function setSpice(?int $spice): self { $this->spice = max(0, min(3, (int) $spice)); return $this; }
    public function getStation(): Station { return Station::tryFrom((string) $this->stationName) ?? Station::HOT; }
    public function setStation(Station|string|null $station): self { $this->stationName = ($station instanceof Station ? $station : (Station::tryFrom((string) $station) ?? Station::HOT))->value; return $this; }
    public function getStationName(): string { return $this->getStation()->value; }
    public function setStationName(?string $station): self { return $this->setStation($station); }
    public function getSection(): ?MenuSection { return $this->section; }
    public function setSection(?MenuSection $section): self { $this->section = $section; return $this; }
    public function getMenuPosition(): int { return (int) $this->menuPosition; }
    public function setMenuPosition(?int $position): self { $this->menuPosition = (int) $position; return $this; }
    public function isTableOrder(): bool { return false !== $this->tableOrder; }
    public function setTableOrder(?bool $tableOrder): self { $this->tableOrder = (bool) $tableOrder; return $this; }

    /** What a set menu lists; a plain dish has none (asked of any dish by the templates). @return list<string> */
    public function getCourses(): array { return []; }

    /** Its reference for the platforms (Omnifood's Item::$ref) and the tickets. */
    public function getPosRef(): string
    {
        return (string) ($this->getSlug() ?: $this->getId());
    }

    /** Can be ordered now: on sale and in stock. */
    public function isAvailable(): bool
    {
        $stock = $this->getStock();

        return (bool) $this->isForSell() && (null === $stock || $stock > 0);
    }

    /** Run out for now ("rupture" on the pass): still on the menu, greyed. */
    public function isOutOfStock(): bool
    {
        return ProductAvailability::OUT_OF_STOCK === $this->getAvailability() || (null !== $this->getStock() && $this->getStock() <= 0);
    }

    /** On the menu at all: on sale, or only run out for now. */
    public function isOnMenu(): bool
    {
        return $this->isForSell() || ProductAvailability::OUT_OF_STOCK === $this->getAvailability();
    }

    public function runOut(): self
    {
        $this->setAvailability(ProductAvailability::OUT_OF_STOCK);

        return $this;
    }

    public function restock(): self
    {
        $this->setAvailability(ProductAvailability::INSTOCK);
        if (null !== $this->getStock() && $this->getStock() <= 0) {
            $this->setStock(null);
        }

        return $this;
    }

    /** Eaten here: nothing is ever posted. */
    public function isShippable(): bool
    {
        return false;
    }
}
