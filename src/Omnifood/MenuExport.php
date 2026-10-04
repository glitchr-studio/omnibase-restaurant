<?php

namespace Base\Restaurant\Omnifood;

use Base\Marketplace\Service\Pricing;
use Base\Restaurant\Entity\Menu\Dish;
use Base\Restaurant\Repository\DishRepository;
use Omnifood\Model\Allergen;
use Omnifood\Model\Menu\Category;
use Omnifood\Model\Menu\Item;
use Omnifood\Model\Menu\Menu;
use Omnifood\Model\Money;

/**
 * The restaurant's menu in Omnifood's shape, for the delivery platforms:
 * a category per section, an item per dish (its reference is the dish's
 * slug - the one the orders carry back -, its price VAT included, its VAT
 * rate, its EU allergens, its diets as labels), what is run out unavailable.
 * Only the dishes that travel: those orderable away from the table.
 */
class MenuExport
{
    public function __construct(private readonly DishRepository $dishes, private readonly Pricing $pricing)
    {
    }

    public function menu(string $name = 'Menu', string $currency = 'EUR', ?string $photoBase = null): Menu
    {
        $categories = [];
        foreach ($this->dishes->bySection() as $group) {
            $items = [];
            foreach ($group['dishes'] as $dish) {
                $items[] = $this->item($dish, $photoBase);
            }
            if ($items) {
                $section = $group['section'];
                $categories[] = new Category($section ? (string) ($section->getSlug() ?: $section->getId()) : 'other', $section ? (string) $section->getLabel() : $name, $items);
            }
        }

        return new Menu($name, $categories, $currency);
    }

    public function item(Dish $dish, ?string $photoBase = null): Item
    {
        $labels = $dish->getDiets();
        if ($dish->getSpice() > 0) {
            $labels[] = 'spicy';
        }

        return new Item(
            $dish->getPosRef(),
            (string) $dish->getTitle(),
            Money::of($this->pricing->priceWithVat($dish), (string) $dish->getCurrency()),
            $dish->getExcerpt() ?: null,
            round($this->pricing->vatRateFor($dish) * 100, 2),
            array_values(array_filter(array_map(fn (string $a) => Allergen::tryFrom($a), $dish->getAllergens()))),
            $photoBase && $dish->getIllustration() ? rtrim($photoBase, '/').'/'.ltrim($dish->getIllustration(), '/') : null,
            [],
            $dish->isAvailable(),
            $labels,
        );
    }
}
