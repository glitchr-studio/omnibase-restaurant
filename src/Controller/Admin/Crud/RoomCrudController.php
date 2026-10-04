<?php

namespace Base\Restaurant\Controller\Admin\Crud;

use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextField;
use Base\Restaurant\Controller\Admin\OpenToManagersTrait;
use Base\Restaurant\Entity\Room;

/** The rooms: a name, a size; the tables and the walls are drawn in the floor editor (/admin/restaurant/salle). */
class RoomCrudController extends AbstractCrudController
{
    use OpenToManagersTrait;

    public static function getEntityFqcn(): string { return Room::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-border-all'; }

    public function configureActions(Actions $actions): Actions
    {
        return $this->openToManagers(parent::configureActions($actions));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', '@restaurant.admin.room.name')->setColumns(6);
        yield IntegerField::new('width', '@restaurant.admin.room.width')->setColumns(2);
        yield IntegerField::new('height', '@restaurant.admin.room.height')->setColumns(2);
        yield IntegerField::new('position', '@restaurant.admin.position')->setColumns(2);
    }
}
