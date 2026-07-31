<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;

/**
 * End-to-end knowledge-base and rule management against the real served application. Skipped when no
 * database is configured. A fixture admin is created per run; the knowledge bases it makes use a
 * distinctive name and are cleaned up afterwards.
 */
final class KnowledgeBaseCest
{
    private const USERNAME = '__kf_kb_admin__';
    private const PASSWORD = 'KbTestPassw0rd!secure';
    private const KB_NAME = 'ZZ Test Knowledge Base';
    private const KB_SLUG = 'zz-test-knowledge-base';

    public function _before(WebTester $I): void
    {
        $connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));

        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    public function createEditArchiveFlow(WebTester $I): void
    {
        // Create.
        $I->amOnPage('/knowledge-bases/create');
        $I->submitForm('.card form', ['name' => self::KB_NAME, 'description' => 'A description', 'system_instructions' => 'Be brief']);
        $I->seeCurrentUrlEquals('/knowledge-bases/' . self::KB_SLUG);
        $I->see(self::KB_NAME);
        // The admin-facing preparation message replaces the old technical "Provisioning pending" wording.
        $I->see('Preparing the knowledge base');

        // It shows in the list.
        $I->amOnPage('/knowledge-bases');
        $I->see(self::KB_NAME);

        // Edit keeps the slug.
        $I->amOnPage('/knowledge-bases/' . self::KB_SLUG . '/edit');
        $I->submitForm('.card form', ['name' => self::KB_NAME . ' (edited)', 'description' => 'Updated', 'system_instructions' => '']);
        $I->seeCurrentUrlEquals('/knowledge-bases/' . self::KB_SLUG);
        $I->see('(edited)');

        // Archive removes it from the active list but keeps it under "including archived".
        $I->amOnPage('/knowledge-bases/' . self::KB_SLUG);
        $I->submitForm('.page-header form[action*="/archive"]', []);
        $I->amOnPage('/knowledge-bases');
        $I->dontSee(self::KB_NAME);
        $I->amOnPage('/knowledge-bases?archived=1');
        $I->see(self::KB_NAME);
    }

    public function ruleManagementFlow(WebTester $I): void
    {
        $I->amOnPage('/knowledge-bases/create');
        $I->submitForm('.card form', ['name' => self::KB_NAME, 'description' => '', 'system_instructions' => '']);
        $I->seeCurrentUrlEquals('/knowledge-bases/' . self::KB_SLUG);

        // Add a rule.
        $I->submitForm('.card form[action$="/rules"]', ['name' => 'Only from documents', 'instruction' => 'Answer only from the documents.']);
        $I->seeCurrentUrlEquals('/knowledge-bases/' . self::KB_SLUG);
        $I->see('Rule added');
        $I->see('Only from documents');

        // Duplicate name is rejected with a message, and no second copy appears.
        $I->submitForm('.card form[action$="/rules"]', ['name' => 'Only from documents', 'instruction' => 'dup']);
        $I->see('already exists');
    }

    public function invalidCreateShowsFieldError(WebTester $I): void
    {
        $I->amOnPage('/knowledge-bases/create');
        $I->submitForm('.card form', ['name' => '', 'description' => '', 'system_instructions' => '']);
        // Stays on the form (no redirect) and reports the error inline.
        $I->seeInCurrentUrl('/knowledge-bases');
        $I->see('Enter a name');
    }

    public function unknownKnowledgeBaseIs404(WebTester $I): void
    {
        $I->amOnPage('/knowledge-bases/this-does-not-exist');
        $I->seeResponseCodeIs(404);
        $I->see('Not found');
    }

    private function cleanup(): void
    {
        $connection = IntegrationDb::connectOrSkip();
        // Rules cascade with the knowledge base.
        IntegrationDb::cleanup($connection, '{{%knowledge_bases}}', ['slug' => self::KB_SLUG]);
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
