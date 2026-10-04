<?php

namespace Base\Restaurant\Entity\Menu;

use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Thread\Taxon;
use Doctrine\ORM\Mapping as ORM;

/**
 * A part of the menu - "Entrées", "Ramen", "Boissons": an omnibase taxon
 * (its label, translated, its slug, its icon), in the menu's order, with its
 * name in the kitchen's own language under it.
 */
#[ORM\Entity]
#[DiscriminatorEntry(value: 'restaurant_menu_section')]
class MenuSection extends Taxon
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-utensils'];
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $menuPosition = 0;

    /** "前菜": written under the label */
    #[ORM\Column(length: 60, nullable: true)]
    protected ?string $nativeName = null;

    public function getMenuPosition(): int { return (int) $this->menuPosition; }
    public function setMenuPosition(?int $position): self { $this->menuPosition = (int) $position; return $this; }
    public function getNativeName(): ?string { return $this->nativeName; }
    public function setNativeName(?string $name): self { $this->nativeName = trim((string) $name) ?: null; return $this; }
}
