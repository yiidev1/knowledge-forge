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
 * The sidebar carries no Knowledge Bases group.
 *
 * It used to hold an A–Z that filtered the store listing by letter, which duplicated the "Store chat"
 * entry directly above it — the same listing, reached two ways. It was removed on request; this pins
 * that decision, and pins that the listing's own A–Z, which is the one that does the work, is untouched.
 */
final class KnowledgeBasesNavCest
{
    private const USERNAME = '__kf_kbnav_admin__';
    private const PASSWORD = 'KbNavPassw0rd!secure';

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

    public function theSidebarNoLongerCarriesTheKnowledgeBasesAToZ(WebTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/admin/order58/stores?letter=C');

        $I->dontSeeElement('aside.sidebar .sidebar__kb');
        $I->dontSeeElement('aside.sidebar .sidebar__az');
        $I->dontSee('Knowledge Bases', 'aside.sidebar');
    }

    /** The letters on the page itself still work — only the sidebar copy went. */
    public function theStoreListingKeepsItsOwnLetterNavigation(WebTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/admin/order58/stores?letter=C');

        $I->seeElement('.alpha-nav__item');
        $I->seeElement('.alpha-nav__item--active');
    }

    /** And the entries the sidebar does carry are unaffected. */
    public function theRemainingSidebarEntriesStillWork(WebTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/');

        foreach (['Dashboard', 'Order58 stores', 'Store chat', 'Rule Chat', 'Audio to Text'] as $entry) {
            $I->see($entry, 'aside.sidebar');
        }

        $I->click('Store chat', 'aside.sidebar');
        $I->seeInCurrentUrl('/admin/order58/store-chat');
    }

    private function login(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
