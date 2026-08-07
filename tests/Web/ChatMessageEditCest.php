<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\MessageRole;
use App\Chat\Domain\NewMessage;
use App\Chat\Infrastructure\DbConversationRepository;
use App\Chat\Infrastructure\DbMessageRepository;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentStatus;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function str_repeat;

/**
 * The message-editing web surface against the served application, seeded so it needs no live OpenAI: the
 * latest question renders its inline edit affordance, an unanswered latest question blocks the composer and
 * offers a retry, and an unchanged edit is rejected server-side with a flash before any regeneration. The
 * regenerating happy path is covered by the unit tests against the fake provider.
 */
final class ChatMessageEditCest
{
    private const USERNAME = '__kf_edit_admin__';
    private const PASSWORD = 'EditTestPassw0rd!secure';
    private const KB_NAME = 'ZZ Edit Test KB';
    private const KB_SLUG = 'zz-edit-test-kb';

    private ConnectionInterface $connection;
    private int $adminId;
    private int $kbId;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));
        $this->adminId = (int) $this->connection
            ->createCommand('SELECT id FROM {{%admin_users}} WHERE username = :u', [':u' => self::USERNAME])
            ->queryScalar();

        $ts = DbDateTime::format(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => self::KB_NAME,
            'slug' => self::KB_SLUG,
            'vector_store_status' => 'ready',
            // A Ready vector store must carry a (unique) id — the canonical policy null-checks it.
            'openai_vector_store_id' => 'vs_' . self::KB_SLUG,
            'status' => 'active',
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();
        $this->kbId = (int) $this->connection->getLastInsertId();

        // A ready, enabled document with a completed vector-store file, so the base is chat-ready under the
        // usable-snapshot policy (and the edit affordances render).
        $this->connection->createCommand()->insert('{{%documents}}', [
            'knowledge_base_id' => $this->kbId,
            'original_filename' => 'seed.txt',
            'stored_path' => 'seed/seed.md',
            'storage_token' => str_repeat('a', 32),
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'size_bytes' => 10,
            'checksum_sha256' => str_repeat('b', 64),
            'kind' => DocumentKind::Text->value,
            'status' => DocumentStatus::Ready->value,
            'is_enabled' => 1,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();
        $docId = (int) $this->connection->getLastInsertId();
        $this->connection->createCommand()->insert('{{%document_index_files}}', [
            'document_id' => $docId,
            'role' => 'source',
            'index_status' => 'completed',
            'openai_file_id' => 'file_seed',
            'pending_removal' => 0,
            'created_at' => $ts,
            'updated_at' => $ts,
        ])->execute();

        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    public function latestQuestionRendersInlineEditAffordance(WebTester $I): void
    {
        $this->seedThread(answered: true);

        $I->amOnPage('/knowledge-bases/' . self::KB_SLUG . '/chat');

        $I->seeElement('form[data-edit-form]');
        // Only the latest user message is editable — one toggle, none on the assistant answer.
        $I->seeNumberOfElements('[data-edit-toggle]', 1);
        // The composer is available because the latest question is answered.
        $I->seeElement('textarea[name=question]');
        $I->dontSeeElement('[data-composer-blocked]');
    }

    public function unansweredLatestBlocksComposerAndOffersRetry(WebTester $I): void
    {
        $this->seedThread(answered: false);

        $I->amOnPage('/knowledge-bases/' . self::KB_SLUG . '/chat');

        $I->seeElement('form[data-retry-form]');
        $I->seeElement('[data-composer-blocked]');
        // The composer question box is withheld until the pending answer is resolved.
        $I->dontSeeElement('textarea[name=question]');
    }

    public function unchangedEditIsRejectedServerSideWithoutRegenerating(WebTester $I): void
    {
        $this->seedThread(answered: true);

        $I->amOnPage('/knowledge-bases/' . self::KB_SLUG . '/chat');
        // The edit form is in the DOM (hidden until toggled); submitting it unchanged is refused before any
        // provider call — no live OpenAI needed to reach this branch.
        $I->submitForm('form[data-edit-form]', ['content' => 'Seeded question']);

        $I->see('same as the current one');
    }

    /**
     * @return array{0: int, 1: int} [conversationId, latest user message id]
     */
    private function seedThread(bool $answered): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $conversationId = (new DbConversationRepository($this->connection))->createThread(
            $this->kbId,
            ChatParticipant::admin($this->adminId),
            self::KB_NAME,
            $now,
        );

        $messages = new DbMessageRepository($this->connection);
        $userId = $messages->add(NewMessage::user($conversationId, 'Seeded question'), $now);
        if ($answered) {
            $messages->insertActiveAnswer(
                new NewMessage(
                    conversationId: $conversationId,
                    role: MessageRole::Assistant,
                    content: 'Seeded answer',
                    replyToMessageId: $userId,
                ),
                $now,
            );
        }

        return [$conversationId, $userId];
    }

    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();
        // conversations, messages, message_revisions and documents all cascade from the knowledge base.
        IntegrationDb::cleanup($connection, '{{%knowledge_bases}}', ['slug' => self::KB_SLUG]);
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
