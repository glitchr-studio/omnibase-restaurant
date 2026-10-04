<?php

namespace Base\Restaurant\Controller\Admin\Crud;

use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\BooleanField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SelectField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Restaurant\Controller\Admin\OpenToManagersTrait;
use Base\Restaurant\Entity\Layout;
use Base\Restaurant\Entity\MealService;

/**
 * The meal services: days, first and last seating, step, how long a table
 * is kept by party size, covers in all, automatic confirmation, layout.
 * When the restaurant opens at all, and its days off, are omnibase's hours.
 */
class MealServiceCrudController extends AbstractCrudController
{
    use OpenToManagersTrait;

    public static function getEntityFqcn(): string { return MealService::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-bell-concierge'; }

    public function configureActions(Actions $actions): Actions
    {
        return $this->openToManagers(parent::configureActions($actions));
    }

    public function configureFields(string $pageName): iterable
    {
        $days = [];
        foreach ([1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'] as $n => $day) {
            $days['@restaurant.day.'.$day] = $n;
        }
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', '@restaurant.admin.service.name')->setColumns(4);
        yield TextField::new('firstSeating', '@restaurant.admin.service.first')->setColumns(2);
        yield TextField::new('lastSeating', '@restaurant.admin.service.last')->setColumns(2);
        yield IntegerField::new('step', '@restaurant.admin.service.step')->setColumns(2);
        yield BooleanField::new('active', '@restaurant.admin.service.active')->setColumns(2);
        yield SelectField::new('weekdays', '@restaurant.admin.service.weekdays')->setChoices($days)->allowMultipleChoices()->setColumns(6);
        yield SelectField::new('layout', '@restaurant.admin.service.layout')->setClass(Layout::class)->setRequired(false)->setColumns(6);
        yield IntegerField::new('maxCovers', '@restaurant.admin.service.max_covers')->setRequired(false)->setColumns(3);
        yield IntegerField::new('autoConfirm', '@restaurant.admin.service.auto_confirm')->setRequired(false)->setColumns(3)->setHelp('@restaurant.admin.service.auto_confirm_help');
        yield TextareaField::new('durationsText', '@restaurant.admin.service.durations')->hideOnIndex()->setColumns(6)->setHelp('@restaurant.admin.service.durations_help');
        yield IntegerField::new('position', '@restaurant.admin.position')->setColumns(2)->hideOnIndex();
    }
}
