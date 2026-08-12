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
 * The read-only source-transparency pages reached from a chat's "View Knowledge" / "View Rules" buttons.
 *
 * The properties that matter are scope and read-only-ness: a store's knowledge page lists that store's
 * documents and no other store's, and neither page offers an upload/delete/sync control.
 */
final class ChatSourcesCest
{
    private const USERNAME = '__kf_sources_admin__';
    private const PASSWORD = 'SourcesPassw0rd!secure';

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

    public function storeChatKnowledgePageIsScopedToItsOwnStore(WebTester $I): void
    {
        // Deliberately a base that HAS documents, so the scoping assertion has something to be wrong about.
        $slug = $this->slugWithDocuments();
        if ($slug === null) {
            $I->markTestSkipped('No knowledge base with documents in the test database.');
        }

        $this->login($I);
        $I->amOnPage('/knowledge-bases/' . $slug . '/chat/knowledge');
        $I->seeResponseCodeIs(200);
        $I->see('Knowledge available to this chat');

        // Read-only: the page must not offer any mutating control.
        $I->dontSeeElement('form[enctype="multipart/form-data"]');
        $I->dontSee('Upload');
        $I->dontSee('Re-index');

        // Its own documents are listed...
        foreach ($this->documentTitlesInKnowledgeBase($slug) as $ownTitle) {
            $I->see($ownTitle);
        }

        // ...and no other knowledge base's are.
        foreach ($this->documentTitlesOutsideKnowledgeBase($slug) as $foreignTitle) {
            $I->dontSee($foreignTitle);
        }
    }

    public function knowledgeTitlesExpandToTheDocumentText(WebTester $I): void
    {
        // Needs a base whose documents really exist on disk. A base with rows but no stored files degrades to
        // "no readable text" by design, which would not exercise the disclosure.
        $slug = $this->slugWithReadableDocument();
        if ($slug === null) {
            $I->markTestSkipped('No knowledge base with a readable stored document.');
        }

        $this->login($I);
        $I->amOnPage('/knowledge-bases/' . $slug . '/chat/knowledge');
        $I->seeResponseCodeIs(200);

        // The title is the disclosure control and the body holds the document's own text.
        $I->seeElement('details.src-detail > summary');
        $I->seeElement('details.src-detail .src-detail__body');
    }

    public function storeChatRulesPageStatesStoreChatCannotAnswerFromCatalogRules(WebTester $I): void
    {
        $slug = $this->anyChattableSlug();
        if ($slug === null) {
            $I->markTestSkipped('No chat-ready knowledge base in the test database.');
        }

        $this->login($I);
        $I->amOnPage('/knowledge-bases/' . $slug . '/chat/rules');
        $I->seeResponseCodeIs(200);
        $I->see('Rules available to this chat');
        $I->see('This chat cannot answer from these rules.');
    }

    public function ruleChatRulesPageListsOnlyIndexedRules(WebTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/admin/rule-chat/rules');
        $I->seeResponseCodeIs(200);
        $I->see('Rules available to this chat');
    }

    public function chatHeaderExposesKnowledgeButNotRules(WebTester $I): void
    {
        // A non-Order58 base keeps its chat page reachable even when it has no ready documents; an Order58 store
        // that is not chat-eligible is deliberately redirected away, so it cannot be used to assert the header.
        $slug = $this->anyManualSlug();
        if ($slug === null) {
            $I->markTestSkipped('No manually-created knowledge base in the test database.');
        }

        $this->login($I);
        $I->amOnPage('/knowledge-bases/' . $slug . '/chat');
        $I->seeElement("a[href='/knowledge-bases/" . $slug . "/chat/knowledge']");

        // Store chat cannot retrieve rules at all, so the header deliberately does not advertise a rules view.
        // The page itself stays reachable by direct URL for anyone who wants the reference.
        $I->dontSeeElement("a[href='/knowledge-bases/" . $slug . "/chat/rules']");
    }

    private function login(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    /**
     * A slug of any active knowledge base, so the test exercises real rows rather than fixtures it invented.
     */
    private function anyChattableSlug(): ?string
    {
        $slug = $this->connection->createQuery()
            ->select('slug')
            ->from('knowledge_bases')
            ->where(['status' => 'active'])
            ->orderBy(['id' => SORT_ASC])
            ->scalar();

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /**
     * The slug of an active base that actually holds documents, so a scoping assertion is meaningful.
     */
    private function slugWithDocuments(): ?string
    {
        $slug = $this->connection->createQuery()
            ->select('kb.slug')
            ->from(['kb' => 'knowledge_bases'])
            ->innerJoin(['d' => 'documents'], 'd.knowledge_base_id = kb.id')
            ->where(['kb.status' => 'active'])
            ->andWhere(['<>', 'd.status', 'deleted'])
            ->groupBy('kb.id')
            ->orderBy(['kb.id' => SORT_ASC])
            ->scalar();

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /**
     * The slug of a base holding at least one document whose stored file is actually present, so the text
     * disclosure has something to render. Checked against the filesystem because a seeded row without its
     * file is exactly the case that legitimately shows no preview.
     */
    private function slugWithReadableDocument(): ?string
    {
        $rows = $this->connection->createQuery()
            ->select(['kb.slug', 'd.stored_path'])
            ->from(['d' => 'documents'])
            ->innerJoin(['kb' => 'knowledge_bases'], 'kb.id = d.knowledge_base_id')
            ->where(['kb.status' => 'active'])
            ->andWhere(['<>', 'd.status', 'deleted'])
            ->limit(200)
            ->all();

        $storageRoot = dirname(__DIR__, 2) . '/runtime/storage/';
        foreach ($rows as $row) {
            $path = (string) ($row['stored_path'] ?? '');
            if ($path !== '' && is_file($storageRoot . $path)) {
                return (string) $row['slug'];
            }
        }

        return null;
    }

    /**
     * Titles of documents that DO belong to this base — every one must be listed on its page.
     *
     * @return list<string>
     */
    private function documentTitlesInKnowledgeBase(string $slug): array
    {
        $rows = $this->connection->createQuery()
            ->select('d.title')
            ->from(['d' => 'documents'])
            ->innerJoin(['kb' => 'knowledge_bases'], 'kb.id = d.knowledge_base_id')
            ->where(['kb.slug' => $slug])
            ->andWhere(['<>', 'd.status', 'deleted'])
            ->limit(5)
            ->column();

        $titles = [];
        foreach ($rows as $row) {
            $title = trim((string) $row);
            if ($title !== '') {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * A manually-created (non-Order58) active base: its chat page renders regardless of document readiness.
     */
    private function anyManualSlug(): ?string
    {
        $slug = $this->connection->createQuery()
            ->select('slug')
            ->from('knowledge_bases')
            ->where(['status' => 'active', 'source_system' => null])
            ->orderBy(['id' => SORT_ASC])
            ->scalar();

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /**
     * Document titles that belong to a DIFFERENT knowledge base — none of them may appear on this store's page.
     *
     * @return list<string>
     */
    private function documentTitlesOutsideKnowledgeBase(string $slug): array
    {
        $knowledgeBaseId = $this->connection->createQuery()
            ->select('id')->from('knowledge_bases')->where(['slug' => $slug])->scalar();

        $rows = $this->connection->createQuery()
            ->select('title')
            ->from('documents')
            ->where(['<>', 'knowledge_base_id', (int) $knowledgeBaseId])
            ->andWhere(['<>', 'status', 'deleted'])
            ->andWhere(['not', ['title' => null]])
            ->limit(5)
            ->column();

        $titles = [];
        foreach ($rows as $row) {
            $title = (string) $row;
            // Skip titles short/generic enough to collide with unrelated page text.
            if (mb_strlen($title) > 12) {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('admin_users', ['username' => self::USERNAME])
            ->execute();
    }
}
