<?php

namespace Base\Restaurant\Controller\Admin\Crud;

use Base\Enum\Allergen;
use Base\Field\BooleanField;
use Base\Field\IntegerField;
use Base\Field\SelectField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Marketplace\Controller\Admin\Crud\ProductCrudController;
use Base\Restaurant\Entity\Product\TakeHome;

/**
 * The take-home shop's fresh dishes: the shop's product screen (title, text,
 * price before VAT, stock, availability) with how each keeps, how it is
 * cooked at home, and whether it travels in a chilled parcel.
 */
class TakeHomeCrudController extends ProductCrudController
{
    public static function getEntityFqcn(): string { return TakeHome::class; }

    public static function getPreferredIcon(): ?string { return 'fa-solid fa-snowflake'; }

    public function configureFields(string $pageName): iterable
    {
        yield from parent::configureFields($pageName);

        $allergens = [];
        foreach (Allergen::cases() as $allergen) {
            $allergens['@enums.'.$allergen->trans()] = $allergen->value;
        }
        yield TextField::new('nativeName', '@restaurant.admin.takehome.native')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield IntegerField::new('portions', '@restaurant.admin.takehome.portions')->setColumns(2)->hideOnIndex();
        yield IntegerField::new('netWeight', '@restaurant.admin.takehome.weight')->setRequired(false)->setColumns(2)->hideOnIndex();
        yield IntegerField::new('shopPosition', '@restaurant.admin.takehome.position')->setColumns(2)->hideOnIndex();
        yield BooleanField::new('chilled', '@restaurant.admin.takehome.chilled')->setColumns(2)->hideOnIndex();
        yield IntegerField::new('storageTemperature', '@restaurant.admin.takehome.temperature')->setColumns(2)->hideOnIndex();
        yield IntegerField::new('shelfLife', '@restaurant.admin.takehome.shelf_life')->setColumns(3);
        yield BooleanField::new('parcel', '@restaurant.admin.takehome.parcel')->setColumns(3);
        yield IntegerField::new('cookingTime', '@restaurant.admin.takehome.cooking_time')->setRequired(false)->setColumns(2)->hideOnIndex();
        yield TextareaField::new('cookingText', '@restaurant.admin.takehome.cooking')->setRequired(false)->hideOnIndex();
        yield SelectField::new('allergens', '@restaurant.admin.takehome.allergens')->setChoices($allergens)->allowMultipleChoices()->setRequired(false)->setColumns(6)->hideOnIndex();
        yield TextField::new('illustration', '@restaurant.admin.takehome.illustration')->setRequired(false)->setColumns(6)->hideOnIndex();
    }
}
