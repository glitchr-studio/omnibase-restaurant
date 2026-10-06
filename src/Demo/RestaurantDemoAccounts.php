<?php

namespace Base\Restaurant\Demo;

use Base\Demo\DemoAccount;
use Base\Demo\DemoAccountProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The demonstration accounts of a restaurant (glitchr/omnibase's `demo`
 * environment): one for each of its people. The manager holds
 * restaurant.manager_role - the floor plan, the menu, the services, the
 * book; the floor team and the kitchen hold restaurant.staff_role through
 * two groups, "Salle" and "Cuisine", as in a real restaurant (omnibase
 * stores no ROLE_STAFF on an account) - both run the pass, the kitchen one
 * station at a time (/service?poste=hot); the customer holds no role of the
 * restaurant: the take-home shop, an order paid and followed.
 *
 * An application's fixtures take them from Base\Demo\DemoAccountFactory
 * ($accounts->account('gerant', $manager)) and attach what makes them worth
 * signing in as: the store and its dishes to the manager, a day's
 * reservations for the pass. A restaurant without one of them leaves it out:
 * base.demo.exclude.
 *
 * Registered when the installed glitchr/omnibase has the demo environment
 * (config/services.php).
 */
final class RestaurantDemoAccounts implements DemoAccountProviderInterface
{
    public const FLOOR = 'Salle';
    public const KITCHEN = 'Cuisine';

    public function __construct(
        #[Autowire('%restaurant.manager_role%')] private readonly string $managerRole = 'ROLE_ADMIN',
        #[Autowire('%restaurant.staff_role%')] private readonly string $staffRole = 'ROLE_STAFF',
    ) {
    }

    public function getDemoAccounts(): iterable
    {
        yield new DemoAccount('gerant', '@restaurant.demo.gerant.label', '@restaurant.demo.gerant.description', roles: [$this->managerRole], position: 10);
        yield new DemoAccount('salle', '@restaurant.demo.salle.label', '@restaurant.demo.salle.description', group: self::FLOOR, groupRoles: [$this->staffRole], position: 20);
        yield new DemoAccount('cuisine', '@restaurant.demo.cuisine.label', '@restaurant.demo.cuisine.description', group: self::KITCHEN, groupRoles: [$this->staffRole], position: 30);
        yield new DemoAccount('client', '@restaurant.demo.client.label', '@restaurant.demo.client.description', position: 40);
    }
}
