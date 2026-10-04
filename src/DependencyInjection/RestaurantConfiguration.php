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
