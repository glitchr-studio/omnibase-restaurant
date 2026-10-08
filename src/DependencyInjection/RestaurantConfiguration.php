<?php

namespace Base\Restaurant\DependencyInjection;

use Base\Bundle\AbstractBaseConfiguration;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class RestaurantConfiguration extends AbstractBaseConfiguration
{
    private bool $childrenDeclared = false;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = $this->getTreeBuilder();
        if ($this->childrenDeclared) {
            return $treeBuilder;
        }
        $this->childrenDeclared = true;

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('layout')->defaultValue('layout1.html.twig')->info('The site\'s page frame the public pages extend (blocks title, content, stylesheets, javascripts).')->end()
                ->scalarNode('staff_role')->defaultValue('ROLE_STAFF')->info('Who runs the pass (/service): seats guests, moves tickets along, settles tables.')->end()
                ->scalarNode('manager_role')->defaultValue('ROLE_ADMIN')->info('Who edits the floor plan, prints the QR posters, pauses the platforms.')->end()
                ->scalarNode('from_email')->defaultNull()->info('Sender of the reservation mails; null: the mailer\'s default sender.')->end()
                ->scalarNode('notify_email')->defaultNull()->info('Where a reservation request to confirm is announced; null: nobody is mailed.')->end()
                ->arrayNode('reservation')->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('notice')->min(0)->defaultValue(60)->info('Minutes between now and the first time a guest may book online.')->end()
                        ->integerNode('horizon')->min(1)->defaultValue(60)->info('Days ahead a guest may book online.')->end()
                        ->integerNode('max_covers')->min(1)->defaultValue(8)->info('More guests than this: the form says to call.')->end()
                        ->integerNode('min_delay')->min(0)->defaultValue(3)->info('Seconds: a form sent faster than this is a robot\'s.')->end()
                        ->integerNode('cancel_until')->min(0)->defaultValue(120)->info('Minutes before the time a guest may still change or cancel online.')->end()
                    ->end()
                ->end()
                ->arrayNode('table')->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('ordering')->defaultTrue()->info('Guests order from their phone after scanning their table\'s code; false: the code shows the menu only.')->end()
                        ->booleanNode('pay_online')->defaultFalse()->info('"Pay from my phone" at the end (needs omnibase/marketplace\'s quick order); false: the bill is settled at the till.')->end()
                        ->integerNode('rate_limit')->min(1)->defaultValue(20)->info('Requests a table\'s phones may make in a minute (rounds, calls, bill).')->end()
                        ->integerNode('max_lines')->min(1)->defaultValue(40)->info('Lines a round may hold.')->end()
                        ->integerNode('max_quantity')->min(1)->defaultValue(20)->info('Of one dish in one round.')->end()
                    ->end()
                ->end()
                // The take-home shop: fresh dishes to cook at home (Entity\Product\TakeHome, Service\ColdChain).
                ->arrayNode('takehome')->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->info('The shop\'s pages (/traiteur) answer.')->end()
                        ->booleanNode('pickup')->defaultTrue()->info('Orders are collected at the restaurant, on a slot (omnibase/marketplace\'s marketplace.pickup.slots).')->end()
                        ->integerNode('notice_days')->min(0)->defaultValue(1)->info('Days the kitchen needs: 1, an order is for tomorrow at the earliest; 0, for today.')->end()
                        ->integerNode('max_quantity')->min(1)->defaultValue(20)->info('Of one dish in one order.')->end()
                    ->end()
                ->end()
                ->arrayNode('delivery')->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('zip_codes')->scalarPrototype()->end()->defaultValue([])->info('Where the restaurant brings orders itself: postcodes, or a prefix ("67*"). Empty: no local delivery.')->end()
                        ->integerNode('minimum')->min(0)->defaultValue(0)->info('The smallest basket delivered, in cents before VAT.')->end()
                        ->scalarNode('fee_product')->defaultNull()->info('Slug of the product sold as the delivery charge (a line of the order); null: delivered free.')->end()
                    ->end()
                ->end()
                ->arrayNode('parcel')->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('shipping_method')->defaultNull()->info('Slug of the shop\'s shipping method that carries chilled parcels (its carrier: a glitchr/omnibus gateway, omnibus/chronopost with product "fresh"). Null, or its carrier not configured: no parcel.')->end()
                        ->scalarNode('fee_product')->defaultNull()->info('Slug of the product sold as the parcel\'s charge (a line of the order).')->end()
                        ->arrayNode('ship_days')->integerPrototype()->min(1)->max(7)->end()->defaultValue([1, 2, 3])->info('ISO weekdays a parcel is handed to the carrier: Monday to Wednesday.')->end()
                        ->integerNode('transit_days')->min(1)->defaultValue(1)->info('Days on the road.')->end()
                        ->integerNode('margin_days')->min(0)->defaultValue(2)->info('Days of use-by date left on arrival, at least.')->end()
                        ->scalarNode('cutoff')->defaultValue('12:00')->info('With notice_days 0: past this hour, a parcel leaves tomorrow at the earliest.')->end()
                    ->end()
                ->end()
                // What guests say of the restaurant elsewhere (glitchr/omnireview): its rating and latest reviews on its
                // page, a tile of the back office's dashboard. Nothing shows without the family, nor without a key.
                ->arrayNode('reviews')->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('places')->useAttributeAsKey('gateway')->scalarPrototype()->end()->defaultValue([])->info('The restaurant on each platform, by its omnireview gateway\'s name: {google: "ChIJ...", tripadvisor: "1234567", trustpilot: "<business unit>"}.')->end()
                        ->arrayNode('owners')->useAttributeAsKey('gateway')->scalarPrototype()->end()->defaultValue([])->info('The owner\'s side of a platform: {google: "accounts/<a>/locations/<l>"} (Business Profile: every review, and whether it was answered).')->end()
                        ->integerNode('limit')->min(0)->defaultValue(3)->info('Reviews shown per platform on the restaurant\'s page.')->end()
                    ->end()
                ->end()
                ->arrayNode('pass')->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('poll')->min(2)->defaultValue(6)->info('Seconds between two looks at the state by the pass and the guests\' phones.')->end()
                        ->integerNode('accept_warning')->min(0)->defaultValue(180)->info('Seconds before a platform order\'s deadline the pass turns it red.')->end()
                    ->end()
                ->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
