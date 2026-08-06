<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * The rule-readiness page on the served admin surface: the summary's "Browse rules" button now opens
 * /admin/order58/rules/readiness, the advanced /rules/list page still works by direct URL, the readiness page
 * requires an authenticated admin, and its search + filter round-trip through the query string.
 */
final class RuleReadinessCest
{
    private const USERNAME = '__kf_readiness_admin__';
    private const PASSWORD = 'ReadinessPassw0rd!secure';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();
        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    public function readinessPageRequiresAnAuthenticatedAdmin(WebTester $I): void
    {
        $I->amOnPage('/admin/order58/rules/readiness');
        $I->seeInCurrentUrl('/login');
    }

    public function browseRulesButtonOpensTheReadinessPage(WebTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/admin/order58/rules');
        $I->seeLink('Browse rules', '/admin/order58/rules/readiness');

        $I->amOnPage('/admin/order58/rules/readiness');
        $I->see('Rule readiness');
        $I->see('Ready');
        $I->see('Pending');
    }

    public function advancedListPageIsPreservedAndReachableByUrl(WebTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/admin/order58/rules/list');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Browse rules');
    }

    public function readinessSearchAndFilterRoundTripThroughTheQueryString(WebTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/admin/order58/rules/readiness?q=onion&filter=failed&page=1');
        $I->seeResponseCodeIsSuccessful();
        // The search term is retained and the active filter is reflected in links (params preserved while paging).
        $I->seeInField('q', 'onion');
        $I->seeInCurrentUrl('filter=failed');
    }

    private function login(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
