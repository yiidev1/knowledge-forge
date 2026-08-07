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
 * The Rule list nav page: it is linked in the sidebar, renders the Rules sync-freshness banner, and its
 * Sync Rules button enqueues a rules sync (structurally asserted — the test never triggers a network sync)
 * and returns to the rule list. Requires an authenticated admin.
 */
final class RuleListPageCest
{
    private const USERNAME = '__kf_rulelist_admin__';
    private const PASSWORD = 'RuleListPassw0rd!secure';

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

    public function ruleListPageRequiresAnAuthenticatedAdmin(WebTester $I): void
    {
        $I->amOnPage('/admin/order58/rules/list');
        $I->seeInCurrentUrl('/login');
    }

    public function ruleListShowsFreshnessAndAWorkingSyncButtonAndSidebarLink(WebTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/admin/order58/rules/list');
        $I->see('Rule list');
        // The independent Rules sync-freshness banner.
        $I->see('Rules sync:');
        $I->see('Last successful sync');
        // The Sync Rules button enqueues a rules sync and returns here (asserted structurally, no sync run).
        $I->seeElement("form[action*='/admin/order58/sync'] input[name='operation'][value='rules']");
        $I->seeElement("form[action*='/admin/order58/sync'] input[name='return'][value='order58.rules.list']");
        // Sidebar navigation exposes BOTH the Rule list and Rule readiness pages.
        $I->seeLink('Rule list', '/admin/order58/rules/list');
        $I->seeLink('Rule readiness', '/admin/order58/rules/readiness');
    }

    public function ruleReadinessPageAlsoHasFreshnessAndTheSameSyncButton(WebTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/admin/order58/rules/readiness');
        $I->see('Rule readiness');
        $I->see('Rules sync:');
        // Same enqueue-only flow, returning to the readiness page.
        $I->seeElement("form[action*='/admin/order58/sync'] input[name='operation'][value='rules']");
        $I->seeElement("form[action*='/admin/order58/sync'] input[name='return'][value='order58.rules.readiness']");
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
