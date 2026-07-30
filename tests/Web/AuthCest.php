<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;

/**
 * End-to-end authentication against the real served application, with the browser managing cookies and
 * the session-bound CSRF token exactly as a user's browser would. Skipped when no database is
 * configured. The fixture admin is created and removed per run so the suite is repeatable.
 */
final class AuthCest
{
    private const USERNAME = '__kf_web_admin__';
    private const PASSWORD = 'TestPassw0rd!secure';

    public function _before(WebTester $I): void
    {
        $connection = IntegrationDb::connectOrSkip();
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);

        $repository = new DbAdminUserRepository($connection, new SystemClock());
        $repository->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));
    }

    public function _after(WebTester $I): void
    {
        $connection = IntegrationDb::connectOrSkip();
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }

    public function validCredentialsReachTheDashboard(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);

        $I->seeCurrentUrlEquals('/');
        $I->see('Welcome back');
        $I->see(self::USERNAME);
    }

    public function invalidCredentialsShowAGenericError(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => 'wrong-password']);

        $I->seeCurrentUrlEquals('/login');
        $I->see('Invalid username or password');
        // The failure must not reveal whether the username exists.
        $I->dontSee('Welcome back');
    }

    public function signingOutReturnsToLoginAndProtectsTheDashboard(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');

        $I->submitForm('.sidebar__logout', []);
        $I->seeCurrentUrlEquals('/login');
        $I->see('signed out');

        // The dashboard is no longer reachable once signed out.
        $I->amOnPage('/');
        $I->seeCurrentUrlEquals('/login');
    }
}
