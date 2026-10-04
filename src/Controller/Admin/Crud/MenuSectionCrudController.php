<?php

namespace Base\Restaurant\Controller\Admin\Crud;

use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SlugField;
use Base\Field\TextField;
use Base\Restaurant\Controller\Admin\OpenToManagersTrait;
use Base\Restaurant\Entity\Menu\MenuSection;

/** The menu's sections, in their order. */
class MenuSectionCrudController extends AbstractCrudController
{
    use OpenToManagersTrait;

    public static function getEntityFqcn(): string { return MenuSection::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-utensils'; }

    public function configureActions(Actions $actions): Actions
    {
        return $this->openToManagers(parent::configureActions($actions));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('label', '@restaurant.admin.section.label')->setColumns(5);
        yield TextField::new('nativeName', '@restaurant.admin.section.native')->setRequired(false)->setColumns(3);
        yield SlugField::new('slug')->setColumns(2)->hideOnIndex();
        yield IntegerField::new('menuPosition', '@restaurant.admin.position')->setColumns(2);
    }
}
