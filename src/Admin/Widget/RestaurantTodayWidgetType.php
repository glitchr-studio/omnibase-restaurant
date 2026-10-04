<?php

namespace Base\Restaurant\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\DashboardWidgetTypeInterface;
use Base\Restaurant\Repository\ReservationRepository;
use Base\Restaurant\Repository\TicketRepository;
use Base\Restaurant\Service\Availability;

/** The day at a glance: covers booked, reservations to confirm, tickets on the pass. */
final class RestaurantTodayWidgetType implements DashboardWidgetTypeInterface
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly TicketRepository $tickets,
        private readonly Availability $availability,
    ) {
    }

    public static function getName(): string
    {
        return 'restaurant_today';
    }

    public function getTemplate(): string
    {
        return '@Restaurant/admin/widget/today.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        $today = $this->availability->today();

        return ['tiles' => [
            'covers' => ['value' => $this->reservations->coversOn($today), 'route' => 'restaurant_pass'],
            'to_confirm' => ['value' => \count($this->reservations->toConfirm($today)), 'route' => 'restaurant_pass', 'alert' => true],
            'tickets' => ['value' => $this->tickets->countOpen(), 'route' => 'restaurant_pass'],
        ]];
    }
}
