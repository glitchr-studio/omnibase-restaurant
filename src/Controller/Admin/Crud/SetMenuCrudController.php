<?php

namespace Base\Restaurant\Controller\Admin\Crud;

use Base\Field\SelectField;
use Base\Field\TextareaField;
use Base\Marketplace\Controller\Admin\Crud\ProductCrudController;
use Base\Restaurant\Entity\MealService;
use Base\Restaurant\Entity\Menu\SetMenu;

/** The set menus: a dish whose courses are listed, served at one service when it says so. */
class SetMenuCrudController extends ProductCrudController
{
    public static function getEntityFqcn(): string { return SetMenu::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-layer-group'; }

    public function configureFields(string $pageName): iterable
    {
        yield from parent::configureFields($pageName);
        yield from DishCrudController::menuFields();
        yield TextareaField::new('coursesText', '@restaurant.admin.set_menu.courses')->setRequired(false)->hideOnIndex()->setHelp('@restaurant.admin.set_menu.courses_help');
        yield SelectField::new('onlyAt', '@restaurant.admin.set_menu.only_at')->setClass(MealService::class)->setRequired(false)->setColumns(4)->hideOnIndex();
    }
}
