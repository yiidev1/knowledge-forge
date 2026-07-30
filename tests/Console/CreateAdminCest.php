<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Tests\Support\ConsoleTester;
use App\Tests\Support\IntegrationDb;

/**
 * Drives kf:admin:create end to end against the real database. The suite cleans up the accounts it
 * creates so it can be re-run. Skipped when no database is configured.
 */
final class CreateAdminCest
{
    private const USERNAME = '__kf_cli_admin__';

    public function _before(ConsoleTester $I): void
    {
        $this->cleanup();
    }

    public function _after(ConsoleTester $I): void
    {
        $this->cleanup();
    }

    public function createsAnAdminAndPrintsAGeneratedPassword(ConsoleTester $I): void
    {
        $I->runShellCommand(sprintf('php yii kf:admin:create %s --generate-password', self::USERNAME));

        $I->seeInShellOutput('created');
        $I->seeInShellOutput('Generated password');
        $I->seeResultCodeIs(0);
    }

    public function rejectsADuplicateUsername(ConsoleTester $I): void
    {
        $I->runShellCommand(sprintf('php yii kf:admin:create %s --generate-password', self::USERNAME));
        $I->runShellCommand(sprintf('php yii kf:admin:create %s --generate-password', self::USERNAME), false);

        $I->seeInShellOutput('already exists');
        $I->seeResultCodeIsNot(0);
    }

    public function rejectsAnInvalidUsername(ConsoleTester $I): void
    {
        $I->runShellCommand('php yii kf:admin:create "bad name!" --generate-password', false);

        $I->seeInShellOutput('Username must be');
        $I->seeResultCodeIsNot(0);
    }

    private function cleanup(): void
    {
        $connection = IntegrationDb::connectOrSkip();
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
