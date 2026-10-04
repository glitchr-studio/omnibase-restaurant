<?php

namespace Base\Restaurant\Entity\Menu;

use Base\Database\Attribute\DiscriminatorEntry;
use Base\Restaurant\Entity\MealService;
use Doctrine\ORM\Mapping as ORM;

/**
 * A set menu at one price - "Teishoku du midi: le plat du jour, une soupe
 * miso, un dessert": a dish whose courses are listed, served at one meal
 * service only when it says so. Ordered like any dish; the courses are
 * read on the ticket.
 */
#[ORM\Entity]
#[DiscriminatorEntry(value: 'restaurant_set_menu')]
class SetMenu extends Dish
{
    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-layer-group'];
    }

    /** @var list<string> */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $courses = [];

    #[ORM\ManyToOne(targetEntity: MealService::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?MealService $onlyAt = null;

    /** @return list<string> */
    public function getCourses(): array { return $this->courses ?? []; }

    /** @param list<string>|string|null $courses one per line when a string */
    public function setCourses(array|string|null $courses): self
    {
        $lines = \is_array($courses) ? $courses : preg_split('/\R/', (string) $courses);
        $this->courses = array_values(array_filter(array_map(fn ($l) => trim((string) $l), $lines), fn ($l) => '' !== $l));

        return $this;
    }

    public function getCoursesText(): string { return implode("\n", $this->getCourses()); }
    public function setCoursesText(?string $text): self { return $this->setCourses($text); }
    public function getOnlyAt(): ?MealService { return $this->onlyAt; }
    public function setOnlyAt(?MealService $service): self { $this->onlyAt = $service; return $this; }
}
