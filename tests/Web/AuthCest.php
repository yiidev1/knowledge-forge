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

    // ------------------------------------------------------------------ the one sign-in form

    /** One form for every user: nothing on it names a role, and nothing on it can choose one. */
    public function theOneFormNamesNoRole(WebTester $I): void
    {
        $I->amOnPage('/login');

        $I->see('Sign in', 'h1');
        $I->see('Access your account');
        $I->dontSee('Administrator access');
        $I->dontSeeElement('form [name="role"]');
        $I->dontSeeElement('form [name="realm"]');
        $I->seeElement('form[action="/login"][method="post"] input[name="_csrf"]');
    }

    /** The agent sign-in's old address lands on the one form, so bookmarks keep working. */
    public function theOldAgentAddressLeadsToTheOneForm(WebTester $I): void
    {
        $I->amOnPage('/agent/login');

        $I->seeCurrentUrlEquals('/login');
        $I->see('Access your account');
    }

    /** A role posted alongside the credentials changes nothing: the verified account decides. */
    public function aPostedRoleIsIgnored(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', [
            'username' => self::USERNAME,
            'password' => 'wrong-password',
            'role' => 'admin',
        ]);
        $I->seeCurrentUrlEquals('/login');
        $I->see('Invalid username or password');

        $I->submitForm('form', [
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
            'role' => 'agent',
        ]);
        $I->seeCurrentUrlEquals('/');
        $I->see('Welcome back');
    }

    /** Signed in, the old agent address and the form both send an administrator to their dashboard. */
    public function aSignedInAdministratorIsSentToTheDashboard(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);

        $I->amOnPage('/login');
        $I->seeCurrentUrlEquals('/');

        $I->amOnPage('/agent/login');
        $I->seeCurrentUrlEquals('/');
    }

    /** The agent area stays closed to an administrator session, exactly as before. */
    public function anAdministratorStillCannotEnterTheAgentArea(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);

        $I->amOnPage('/agent');
        $I->seeCurrentUrlEquals('/');
    }
}
