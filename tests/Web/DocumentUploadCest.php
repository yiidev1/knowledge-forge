<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;

use function codecept_data_dir;
use function file_put_contents;

/**
 * End-to-end document upload against the real served application, driving real multipart uploads through
 * the browser. Skipped when no database is configured. Fixtures and rows are cleaned up per run.
 */
final class DocumentUploadCest
{
    private const USERNAME = '__kf_doc_admin__';
    private const PASSWORD = 'DocTestPassw0rd!secure';
    private const SLUG = 'zz-doc-test-kb';

    public function _before(WebTester $I): void
    {
        $connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));
        (new DbKnowledgeBaseRepository($connection, new SystemClock()))
            ->create('ZZ Doc Test KB', self::SLUG, null, null);

        $this->writeFixtures();

        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    public function uploadingAValidPdfQueuesIt(WebTester $I): void
    {
        $I->amOnPage('/knowledge-bases/' . self::SLUG);
        $I->attachFile('input[type=file]', 'kf_valid.pdf');
        $I->submitForm('form[action$="/documents"]', []);

        $I->seeCurrentUrlEquals('/knowledge-bases/' . self::SLUG);
        $I->see('queued for processing');
        $I->see('kf_valid.pdf');
        $I->see('Queued');
    }

    /**
     * A PHP script renamed to .pdf is rejected on server-side content sniffing, and nothing is stored.
     */
    public function uploadingAPhpScriptDisguisedAsPdfIsRejected(WebTester $I): void
    {
        $I->amOnPage('/knowledge-bases/' . self::SLUG);
        $I->attachFile('input[type=file]', 'kf_evil.pdf');
        $I->submitForm('form[action$="/documents"]', []);

        $I->see('not supported');
        $I->dontSee('kf_evil.pdf');
    }

    public function uploadingTheSameFileTwiceIsRejected(WebTester $I): void
    {
        $I->amOnPage('/knowledge-bases/' . self::SLUG);
        $I->attachFile('input[type=file]', 'kf_valid.pdf');
        $I->submitForm('form[action$="/documents"]', []);
        $I->see('kf_valid.pdf');

        $I->amOnPage('/knowledge-bases/' . self::SLUG);
        $I->attachFile('input[type=file]', 'kf_valid.pdf');
        $I->submitForm('form[action$="/documents"]', []);
        $I->see('already been uploaded');
    }

    public function removingADocumentTakesItOffTheList(WebTester $I): void
    {
        $I->amOnPage('/knowledge-bases/' . self::SLUG);
        $I->attachFile('input[type=file]', 'kf_valid.pdf');
        $I->submitForm('form[action$="/documents"]', []);
        $I->see('kf_valid.pdf');

        // The row now carries several action forms (process-now, delete); target the delete one.
        $I->submitForm('form[action$="/delete"]', []);
        $I->see('Document removed');
        $I->dontSee('kf_valid.pdf');
    }

    private function writeFixtures(): void
    {
        file_put_contents(
            codecept_data_dir() . 'kf_valid.pdf',
            "%PDF-1.4\n% web-test\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
        );
        file_put_contents(codecept_data_dir() . 'kf_evil.pdf', "<?php system(\$_GET['c']); ?>\n");
    }

    private function cleanup(): void
    {
        $connection = IntegrationDb::connectOrSkip();

        // Remove any stored files for this knowledge base before the DB row (and its documents) go, so
        // the test leaves no orphans on disk. Resolve the id while the row still exists.
        $kbId = $connection->createQuery()
            ->select('id')
            ->from('{{%knowledge_bases}}')
            ->where(['slug' => self::SLUG])
            ->scalar();
        if ($kbId !== null && $kbId !== false) {
            $this->removeDir(dirname(__DIR__, 2) . '/runtime/storage/knowledge-bases/' . (int) $kbId);
        }

        IntegrationDb::cleanup($connection, '{{%knowledge_bases}}', ['slug' => self::SLUG]);
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);

        foreach (['kf_valid.pdf', 'kf_evil.pdf'] as $file) {
            $path = codecept_data_dir() . $file;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        /** @var list<string> $entries */
        $entries = scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
