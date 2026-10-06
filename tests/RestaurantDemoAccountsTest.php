<?php

namespace Base\Restaurant\Tests;

use Base\Demo\DemoAccountRegistry;
use Base\Restaurant\Demo\RestaurantDemoAccounts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Yaml\Yaml;

/**
 * The demonstration accounts of a restaurant: one for each of its people,
 * the staff's role through their groups, a label and a sentence for each in
 * the bundle's four catalogues - and nobody above the manager.
 */
class RestaurantDemoAccountsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(DemoAccountRegistry::class)) {
            self::markTestSkipped('Requires a glitchr/omnibase with the demo environment.');
        }
    }

    /** The role hierarchy the bundle's documentation gives an application (docs/installation.md). */
    private function registry(RestaurantDemoAccounts $accounts = new RestaurantDemoAccounts()): DemoAccountRegistry
    {
        return new DemoAccountRegistry([$accounts], [], new RoleHierarchy([
            'ROLE_STAFF' => ['ROLE_USER'],
            'ROLE_ADMIN' => ['ROLE_STAFF'],
            'ROLE_SUPERADMIN' => ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH'],
            'ROLE_EDITOR' => ['ROLE_SUPERADMIN'],
        ]));
    }

    public function testOneAccountForEachOfTheRestaurantsPeople(): void
    {
        $accounts = $this->registry()->all();

        self::assertSame(['gerant', 'salle', 'cuisine', 'client'], array_keys($accounts));
        self::assertSame(['ROLE_ADMIN'], $accounts['gerant']->getAllRoles(), 'the manager: restaurant.manager_role');
        self::assertSame(RestaurantDemoAccounts::FLOOR, $accounts['salle']->group);
        self::assertSame(RestaurantDemoAccounts::KITCHEN, $accounts['cuisine']->group);
        foreach (['salle', 'cuisine'] as $staff) {
            self::assertSame(['ROLE_USER'], $accounts[$staff]->roles, 'no ROLE_STAFF stored on the account');
            self::assertSame(['ROLE_USER', 'ROLE_STAFF'], $accounts[$staff]->getAllRoles(), 'the staff: restaurant.staff_role, through the group');
        }
        self::assertSame(['ROLE_USER'], $accounts['client']->getAllRoles(), 'a customer holds no role of the restaurant');
        self::assertNull($accounts['client']->group);
        self::assertSame('salle', $accounts['salle']->getPassword(), 'the password is the identifier, as in the fixtures');
    }

    public function testTheRolesAreTheOnesTheSiteConfigured(): void
    {
        $accounts = $this->registry(new RestaurantDemoAccounts('ROLE_MANAGER', 'ROLE_FLOOR'))->all();

        self::assertSame(['ROLE_MANAGER'], $accounts['gerant']->getAllRoles());
        self::assertSame(['ROLE_FLOOR'], $accounts['salle']->groupRoles);
        self::assertSame(['ROLE_FLOOR'], $accounts['cuisine']->groupRoles);
    }

    public function testNobodyAboveTheManager(): void
    {
        $registry = $this->registry();
        foreach ($registry->all() as $account) {
            self::assertFalse($registry->reachesSuperAdmin($account->getAllRoles()), $account->identifier);
        }

        // A site whose manager is its super-administrator has no "gerant" button: the registry refuses the declaration.
        $this->expectException(\LogicException::class);
        $this->registry(new RestaurantDemoAccounts('ROLE_SUPERADMIN'))->all();
    }

    public function testEachHasItsLabelAndItsSentenceInTheFourCatalogues(): void
    {
        foreach (['fr', 'en', 'de', 'ja'] as $locale) {
            $catalogue = Yaml::parseFile(\dirname(__DIR__).'/translations/restaurant+intl-icu.'.$locale.'.yaml')['demo'];

            foreach ($this->registry()->all() as $identifier => $account) {
                self::assertSame('@restaurant.demo.'.$identifier.'.label', $account->label);
                self::assertSame('@restaurant.demo.'.$identifier.'.description', $account->description);
                self::assertNotEmpty($catalogue[$identifier]['label'] ?? null, "$identifier in $locale");
                self::assertNotEmpty($catalogue[$identifier]['description'] ?? null, "$identifier in $locale");
            }
        }
    }
}
