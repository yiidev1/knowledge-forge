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
 * The Knowledge Bases A–Z sidebar group: it exposes letter links that open the Order58 store directory filtered
 * by that letter (reusing its SQL pagination/counts — no duplicate listing), and the current letter is
 * highlighted when the directory is active.
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

    public function sidebarExposesTheKnowledgeBasesAToZLinkingTheStoreDirectory(WebTester $I): void
    {
        $this->login($I);

        // On the store directory filtered to "C", the sidebar group is present and the letter links target the
        // directory's ?letter= parameter; the active letter (C) is highlighted.
        $I->amOnPage('/admin/order58/stores?letter=C');
        $I->see('Knowledge Bases');
        $I->seeElement("aside.sidebar a.sidebar__az-item[href*='/admin/order58/stores?letter=A']");
        $I->seeElement("aside.sidebar a.sidebar__az-item--active");
    }

    public function sidebarLetterLinksFollowTheStoreChatPageWhenActive(WebTester $I): void
    {
        $this->login($I);

        // The same A–Z group follows the page you are on: on Store chat the letter links filter Store chat (not the
        // store directory) and the active letter is highlighted there too.
        $I->amOnPage('/admin/order58/store-chat?letter=C');
        $I->see('Knowledge Bases');
        $I->seeElement("aside.sidebar a.sidebar__az-item[href*='/admin/order58/store-chat?letter=A']");
        $I->dontSeeElement("aside.sidebar a.sidebar__az-item[href*='/admin/order58/stores?letter=A']");
        $I->seeElement("aside.sidebar a.sidebar__az-item--active");
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
