<?php

namespace Base\Restaurant\Controller\Admin\Crud;

use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\DateTimeField;
use Base\Field\EmailField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SelectField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Restaurant\Controller\Admin\OpenToManagersTrait;
use Base\Restaurant\Entity\Reservation;
use Base\Restaurant\Enum\ReservationStatus;

/** The reservation book as a list (the pass is where the evening is run). */
class ReservationCrudController extends AbstractCrudController
{
    use OpenToManagersTrait;

    public static function getEntityFqcn(): string { return Reservation::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-book-open'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['startsAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->openToManagers(parent::configureActions($actions));
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('startsAt')->add('source');
    }

    public function configureFields(string $pageName): iterable
    {
        $statuses = [];
        foreach (ReservationStatus::cases() as $status) {
            $statuses['@restaurant.'.$status->trans()] = $status->value;
        }
        yield IdField::new('id')->onlyOnIndex();
        yield DateTimeField::new('startsAt', '@restaurant.admin.reservation.starts_at')->setColumns(3)->setFormTypeOption('disabled', true);
        yield IntegerField::new('covers', '@restaurant.admin.reservation.covers')->setColumns(1);
        yield TextField::new('name', '@restaurant.admin.reservation.name')->setColumns(4);
        yield SelectField::new('statusName', '@restaurant.admin.reservation.status')->setChoices($statuses)->setColumns(2);
        yield TextField::new('tablesLabel', '@restaurant.admin.reservation.tables')->onlyOnIndex();
        yield TextField::new('phone', '@restaurant.admin.reservation.phone')->setRequired(false)->setColumns(3);
        yield EmailField::new('email', '@restaurant.admin.reservation.email')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield TextField::new('source', '@restaurant.admin.reservation.source')->setColumns(2)->setFormTypeOption('disabled', true);
        yield TextField::new('externalRef', '@restaurant.admin.reservation.external')->setRequired(false)->hideOnIndex()->setColumns(4)->setFormTypeOption('disabled', true);
        yield TextareaField::new('allergies', '@restaurant.admin.reservation.allergies')->setRequired(false)->hideOnIndex();
        yield TextareaField::new('notes', '@restaurant.admin.reservation.notes')->setRequired(false)->hideOnIndex();
    }
}
