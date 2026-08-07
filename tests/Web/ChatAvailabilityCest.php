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
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function crc32;
use function str_pad;
use function str_repeat;

/**
 * Chat-availability enforcement on the served admin surface: an Order58-linked store whose only ready
 * document is the store profile must show a disabled "Open chat", a blocked read-only chat page, and reject
 * a direct question POST server-side — including when readiness becomes false after the page was rendered.
 */
final class ChatAvailabilityCest
{
    private const USERNAME = '__kf_avail_admin__';
    private const PASSWORD = 'AvailTestPassw0rd!secure';
    private const PROFILE_SLUG = 'zz-avail-profile-only';   // Order58, profile only → unavailable
    private const READY_SLUG = 'zz-avail-ready';            // has a qualifying doc → available

    private ConnectionInterface $connection;
    private int $adminId;
    private int $profileKbId;
    private int $readyKbId;
    private string $ts;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();
        $this->ts = DbDateTime::format(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));
        $this->adminId = (int) $this->connection
            ->createCommand('SELECT id FROM {{%admin_users}} WHERE username = :u', [':u' => self::USERNAME])
            ->queryScalar();

        // KB 1: Order58-linked, ready vector store, only a store-profile document → chat unavailable.
        $this->profileKbId = $this->insertKb(self::PROFILE_SLUG, order58StoreId: 99001);
        $this->insertUsableDoc($this->profileKbId, 'order58_store_profile');
        // Seed a conversation + history so we can assert read-only rendering.
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $conversationId = (new DbConversationRepository($this->connection))
            ->createThread($this->profileKbId, ChatParticipant::admin($this->adminId), 'Profile store', $now);
        $messages = new DbMessageRepository($this->connection);
        $userId = $messages->add(NewMessage::user($conversationId, 'A seeded past question'), $now);
        $messages->insertActiveAnswer(new NewMessage(
            conversationId: $conversationId,
            role: MessageRole::Assistant,
            content: 'A seeded past answer',
            replyToMessageId: $userId,
        ), $now);

        // KB 2: has a usable qualifying document → chat available.
        $this->readyKbId = $this->insertKb(self::READY_SLUG, order58StoreId: 99002);
        $this->insertUsableDoc($this->readyKbId, 'order58_store_profile');
        $this->insertUsableDoc($this->readyKbId, 'order58_knowledge');

        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    public function openChatIsDisabledWhenOnlyStoreProfileIsReady(WebTester $I): void
    {
        $I->amOnPage('/knowledge-bases/' . self::PROFILE_SLUG);
        $I->seeElement('button[data-chat-unavailable]');
        $I->dontSeeElement('a.btn--primary[href$="/chat"]');
    }

    public function chatGetIsHardBlockedAndRedirectsWhenUnavailable(WebTester $I): void
    {
        // An ineligible Order58 store's chat GET is hard-blocked server-side: it redirects to the store-chat
        // picker instead of rendering the (previously read-only) page, so a direct URL cannot open it.
        $I->amOnPage('/knowledge-bases/' . self::PROFILE_SLUG . '/chat');
        $I->seeInCurrentUrl('/admin/order58/store-chat');
        $I->dontSee('A seeded past question');
        $I->dontSeeElement('textarea[name=question]');
    }

    public function questionPostIsRejectedWhenReadinessBecomesFalseAfterRender(WebTester $I): void
    {
        // The ready store renders a working composer (with CSRF).
        $I->amOnPage('/knowledge-bases/' . self::READY_SLUG . '/chat');
        $I->seeElement('textarea[name=question]');

        // Readiness becomes false: its qualifying document's vector-store file is removed.
        $this->connection->createCommand()->delete('{{%document_index_files}}', [
            'document_id' => $this->qualifyingDocId($this->readyKbId),
        ])->execute();

        // The still-rendered composer POST is rejected server-side (no new answer, unavailable flash).
        $I->submitForm('form.chat__form', ['question' => 'Should be blocked']);
        $I->see('Chat is unavailable');
    }

    private function qualifyingDocId(int $kbId): int
    {
        return (int) $this->connection
            ->createCommand(
                'SELECT id FROM {{%documents}} WHERE knowledge_base_id = :kb AND source_type = :t LIMIT 1',
                [':kb' => $kbId, ':t' => 'order58_knowledge'],
            )->queryScalar();
    }

    private function insertKb(string $slug, int $order58StoreId): int
    {
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => 'Avail ' . $slug,
            'slug' => $slug,
            'vector_store_status' => 'ready',
            // A Ready vector store must carry a (unique) id — the canonical policy null-checks it.
            'openai_vector_store_id' => 'vs_avail_' . $order58StoreId,
            'status' => 'active',
            'source_system' => 'order58',
            'source_store_id' => $order58StoreId,
            // These fixtures represent active source stores (source-inactive is covered by Order58ChatGateCest).
            'source_active' => 1,
            'created_at' => $this->ts,
            'updated_at' => $this->ts,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    private function insertUsableDoc(int $kbId, string $sourceType): void
    {
        $this->connection->createCommand()->insert('{{%documents}}', [
            'knowledge_base_id' => $kbId,
            'original_filename' => $sourceType . '.md',
            'stored_path' => 'p/' . $sourceType . '.md',
            'storage_token' => str_repeat('a', 32),
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'size_bytes' => 10,
            'checksum_sha256' => str_pad((string) crc32($kbId . $sourceType), 64, '0'),
            'kind' => 'text',
            'source_type' => $sourceType,
            'status' => 'ready',
            'is_enabled' => 1,
            'created_at' => $this->ts,
            'updated_at' => $this->ts,
        ])->execute();
        $docId = (int) $this->connection->getLastInsertId();
        $this->connection->createCommand()->insert('{{%document_index_files}}', [
            'document_id' => $docId,
            'role' => 'source',
            'index_status' => 'completed',
            'openai_file_id' => 'file_' . $docId,
            'pending_removal' => 0,
            'created_at' => $this->ts,
            'updated_at' => $this->ts,
        ])->execute();
    }

    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();
        foreach ([self::PROFILE_SLUG, self::READY_SLUG] as $slug) {
            IntegrationDb::cleanup($connection, '{{%knowledge_bases}}', ['slug' => $slug]);
        }
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
