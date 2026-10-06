<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/*
 * Autowiring over src/. Entities, enums, models, events and exceptions are
 * not services. The back office's screens and widgets need omnibase/admin;
 * the platforms' bridge (src/Omnifood) is loaded only when glitchr/omnifood
 * is installed - and its keys' section only with omnibase/admin too. The
 * demonstration accounts (src/Demo) need a glitchr/omnibase with the demo
 * environment.
 */
return function (ContainerConfigurator $configurator) {
    $src = \dirname(__DIR__).'/src';

    // omnibase/marketplace's quick order (Service\QuickOrder): what orders without an account and takes a bill's payment.
    $quickOrder = class_exists('Base\\Marketplace\\Service\\QuickOrder');

    $services = $configurator->services();
    $services->defaults()->autowire(true)->autoconfigure(true)->public(false);

    $services->load('Base\\Restaurant\\', $src.'/')
        ->exclude([
            $src.'/DependencyInjection/',
            $src.'/Entity/',
            $src.'/Enum/',
            $src.'/Model/',
            $src.'/Event/',
            $src.'/Exception/',
            $src.'/Controller/',
            $src.'/Admin/',
            $src.'/Omnifood/',
            $src.'/Demo/',
            $src.'/RestaurantBundle.php',
            // An order without an account, a bill paid from a phone: on omnibase/marketplace's quick order.
            ...($quickOrder ? [] : [$src.'/Service/TakeHomeOrders.php', $src.'/Service/TablePayments.php', $src.'/EventListener/QuickOrderListener.php']),
        ]);

    $services->load('Base\\Restaurant\\Controller\\Client\\', $src.'/Controller/Client/')
        ->exclude($quickOrder ? [] : [$src.'/Controller/Client/TakeHomeOrderController.php', $src.'/Controller/Client/TablePaymentController.php'])
        ->tag('controller.service_arguments');

    // A table's phones: so many requests a minute, by table and address
    // (TableController). The cache keeps the count: no configuration asked of the site.
    $services->set('restaurant.table_limiter.storage', CacheStorage::class)->args([service('cache.app')]);
    $services->set('restaurant.table_limiter', RateLimiterFactory::class)
        ->args([
            ['id' => 'restaurant_table', 'policy' => 'sliding_window', 'limit' => param('restaurant.table.rate_limit'), 'interval' => '1 minute'],
            service('restaurant.table_limiter.storage'),
            service('lock.factory')->nullOnInvalid(),
        ]);

    if (class_exists('Base\\Admin\\Controller\\AbstractCrudController')) {
        $services->load('Base\\Restaurant\\Controller\\Admin\\', $src.'/Controller/Admin/')
            ->tag('controller.service_arguments');
        $services->load('Base\\Restaurant\\Admin\\', $src.'/Admin/');
    }

    // The demonstration accounts of a restaurant, when the installed glitchr/omnibase has the demo environment.
    if (interface_exists('Base\\Demo\\DemoAccountProviderInterface')) {
        $services->load('Base\\Restaurant\\Demo\\', $src.'/Demo/');
    }

    if (class_exists('Omnifood\\Registry')) {
        $services->load('Base\\Restaurant\\Omnifood\\', $src.'/Omnifood/')
            ->exclude(interface_exists('Base\\Admin\\Settings\\SettingsSectionInterface') ? [] : [$src.'/Omnifood/FoodKeysSection.php']);
    }
};
