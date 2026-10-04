<?php

namespace Base\Restaurant\Controller\Admin\Crud;

use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\BooleanField;
use Base\Field\IdField;
use Base\Field\SelectField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Restaurant\Controller\Admin\OpenToManagersTrait;
use Base\Restaurant\Entity\Layout;
use Base\Restaurant\Entity\Room;

/** The layouts by name; the tables are moved and joined in the floor editor. */
class LayoutCrudController extends AbstractCrudController
{
    use OpenToManagersTrait;

    public static function getEntityFqcn(): string { return Layout::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-table-cells'; }

    public function configureActions(Actions $actions): Actions
    {
        return $this->openToManagers(parent::configureActions($actions));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield SelectField::new('room', '@restaurant.admin.table.room')->setClass(Room::class)->setColumns(4);
        yield TextField::new('name', '@restaurant.admin.layout.name')->setColumns(4);
        yield BooleanField::new('default', '@restaurant.admin.layout.default')->setColumns(2);
        yield TextareaField::new('datesText', '@restaurant.admin.layout.dates')->setRequired(false)->hideOnIndex()->setHelp('@restaurant.admin.layout.dates_help');
    }
}
