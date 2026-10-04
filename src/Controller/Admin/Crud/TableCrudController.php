<?php

namespace Base\Restaurant\Controller\Admin\Crud;

use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\BooleanField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SelectField;
use Base\Field\TextField;
use Base\Restaurant\Controller\Admin\OpenToManagersTrait;
use Base\Restaurant\Entity\Room;
use Base\Restaurant\Entity\Table;
use Base\Restaurant\Enum\TableShape;

/** The tables as a list; their places are set in the floor editor. */
class TableCrudController extends AbstractCrudController
{
    use OpenToManagersTrait;

    public static function getEntityFqcn(): string { return Table::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-chair'; }

    public function configureActions(Actions $actions): Actions
    {
        return $this->openToManagers(parent::configureActions($actions));
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('room')->add('active');
    }

    public function configureFields(string $pageName): iterable
    {
        $shapes = [];
        foreach (TableShape::cases() as $shape) {
            $shapes['@restaurant.'.$shape->trans()] = $shape->value;
        }
        yield IdField::new('id')->onlyOnIndex();
        yield SelectField::new('room', '@restaurant.admin.table.room')->setClass(Room::class)->setColumns(4);
        yield TextField::new('label', '@restaurant.admin.table.label')->setColumns(2);
        yield IntegerField::new('minCovers', '@restaurant.admin.table.min')->setColumns(2);
        yield IntegerField::new('maxCovers', '@restaurant.admin.table.max')->setColumns(2);
        yield SelectField::new('shapeName', '@restaurant.admin.table.shape')->setChoices($shapes)->setColumns(2);
        yield BooleanField::new('active', '@restaurant.admin.table.active')->setColumns(2);
        yield BooleanField::new('bookable', '@restaurant.admin.table.bookable')->setColumns(2);
        yield BooleanField::new('ordering', '@restaurant.admin.table.ordering')->setColumns(2);
        yield IntegerField::new('position', '@restaurant.admin.position')->setColumns(2)->hideOnIndex();
    }
}
