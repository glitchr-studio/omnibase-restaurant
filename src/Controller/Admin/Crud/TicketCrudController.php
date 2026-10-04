<?php

namespace Base\Restaurant\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextField;
use Base\Restaurant\Entity\Order\Ticket;

/** The tickets as they were: read only (the pass moves them). */
class TicketCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string { return Ticket::class; }
    public static function getPreferredIcon(): ?string { return 'fa-solid fa-receipt'; }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)->disable(Action::NEW, Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('label', '@restaurant.admin.ticket.label');
        yield TextField::new('platform', '@restaurant.admin.ticket.platform');
        yield TextField::new('externalRef', '@restaurant.admin.ticket.external')->hideOnIndex();
        yield IntegerField::new('total', '@restaurant.admin.ticket.total');
        yield DateTimeField::new('createdAt', '@restaurant.admin.ticket.created_at');
    }
}
