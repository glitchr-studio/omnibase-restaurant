<?php

namespace Base\Restaurant\Controller\Admin\Crud;

use Base\Enum\Allergen;
use Base\Field\BooleanField;
use Base\Field\IntegerField;
use Base\Field\SelectField;
use Base\Field\TextField;
use Base\Marketplace\Controller\Admin\Crud\ProductCrudController;
use Base\Restaurant\Entity\Menu\Dish;
use Base\Restaurant\Entity\Menu\MenuSection;
use Base\Restaurant\Enum\Diet;
use Base\Restaurant\Enum\Station;

/** The dishes: the shop's product screen, and what a menu says besides (native name, section, allergens, diets, spice, station). */
class DishCrudController extends ProductCrudController
{
    public static function getEntityFqcn(): string { return Dish::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-bowl-food'; }

    public function configureFields(string $pageName): iterable
    {
        yield from parent::configureFields($pageName);
        yield from self::menuFields();
    }

    /** @return iterable<mixed> */
    public static function menuFields(): iterable
    {
        $allergens = [];
        foreach (Allergen::cases() as $allergen) {
            $allergens['@enums.'.$allergen->trans()] = $allergen->value;
        }
        $diets = [];
        foreach (Diet::cases() as $diet) {
            $diets['@restaurant.'.$diet->trans()] = $diet->value;
        }
        $stations = [];
        foreach (Station::cases() as $station) {
            $stations['@restaurant.'.$station->trans()] = $station->value;
        }
        yield TextField::new('nativeName', '@restaurant.admin.dish.native')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield SelectField::new('section', '@restaurant.admin.dish.section')->setClass(MenuSection::class)->setRequired(false)->setColumns(4);
        yield IntegerField::new('menuPosition', '@restaurant.admin.position')->setColumns(2)->hideOnIndex();
        yield SelectField::new('stationName', '@restaurant.admin.dish.station')->setChoices($stations)->setColumns(2)->hideOnIndex();
        yield SelectField::new('allergens', '@restaurant.admin.dish.allergens')->setChoices($allergens)->allowMultipleChoices()->setRequired(false)->setColumns(6)->hideOnIndex();
        yield SelectField::new('diets', '@restaurant.admin.dish.diets')->setChoices($diets)->allowMultipleChoices()->setRequired(false)->setColumns(4)->hideOnIndex();
        yield IntegerField::new('spice', '@restaurant.admin.dish.spice')->setColumns(2)->hideOnIndex();
        yield BooleanField::new('tableOrder', '@restaurant.admin.dish.table_order')->setColumns(2)->hideOnIndex();
        yield TextField::new('illustration', '@restaurant.admin.dish.illustration')->setRequired(false)->setColumns(6)->hideOnIndex();
    }
}
