<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Web\Actor\OrderTestingJobScope;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

/**
 * Object-level authorization for the audio endpoints the Order Testing store page drives.
 *
 * Those endpoints are shared with the Audio-to-Text module and are addressed only by `{publicId}`, so
 * without this an operation user who learned any job id could drive it. The columns that make that
 * possible are real: `audio_conversations.store_source_id` and `.order_id` are **both nullable**.
 *
 * The rule is a relationship, not a shape. A conversation carrying a store id and a numeric order id is
 * not enough — the store must exist and the order must be one the mirror actually holds for it, because
 * that is what "reachable from Order Testing" means.
 *
 * Sentinel ids keep the shared dev database undisturbed.
 */
final class OrderTestingJobScopeTest extends Unit
{
    private const STORE = 987655931;
    private const ORDER = 987000111;
    private const ADMIN_ID = 900000801;

    private ConnectionInterface $connection;
    private OrderTestingJobScope $scope;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->scope = new OrderTestingJobScope($this->connection);
        $this->cleanup();

        $this->connection->createCommand()->insert('{{%admin_users}}', [
            'id' => self::ADMIN_ID,
            'username' => '__kf_test_scope_admin__',
            'password_hash' => '$2y$12$notarealhashnotarealhashnotarealhashnotarealhashnotar',
            'is_active' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    /** The whole chain present: a job on a conversation naming a real store and a real mirrored order. */
    public function testAJobReachableFromOrderTestingIsAdmitted(): void
    {
        $this->seedStore();
        $this->seedOrder();
        $publicId = $this->seedJob((string) self::ORDER, self::STORE);

        assertTrue($this->scope->admitsJob($publicId));
    }

    /**
     * Shape alone is not permission: the id looks fine and the order number is numeric, but the mirror
     * holds no such order for that store, so no Order Testing page lists it.
     */
    public function testAJobWhoseOrderIsNotMirroredIsRefused(): void
    {
        $this->seedStore();
        $publicId = $this->seedJob((string) self::ORDER, self::STORE);

        assertFalse($this->scope->admitsJob($publicId));
    }

    /** The order exists, but not for this store. Scoping is per store, as every other lookup here is. */
    public function testAnOrderBelongingToAnotherStoreIsRefused(): void
    {
        $this->seedStore();
        $this->seedOrder(self::STORE + 1);
        $publicId = $this->seedJob((string) self::ORDER, self::STORE);

        assertFalse($this->scope->admitsJob($publicId));
    }

    public function testAJobWhoseStoreIsNotMirroredIsRefused(): void
    {
        $this->seedOrder();
        $publicId = $this->seedJob((string) self::ORDER, self::STORE);

        assertFalse($this->scope->admitsJob($publicId));
    }

    /** The nullable columns, which are exactly why a job id cannot be trusted on its own. */
    public function testAConversationWithNoStoreIsRefused(): void
    {
        $this->seedStore();
        $this->seedOrder();

        assertFalse($this->scope->admitsJob($this->seedJob((string) self::ORDER, null)));
    }

    public function testAConversationWithNoOrderIsRefused(): void
    {
        $this->seedStore();
        $this->seedOrder();

        assertFalse($this->scope->admitsJob($this->seedJob(null, self::STORE)));
    }

    /**
     * `order_id` is free text. Anything that is not a plain positive integer cannot name a mirrored
     * order and is refused rather than cast into one.
     *
     * @dataProvider unusableOrderIds
     */
    public function testANonNumericOrderIdIsRefused(string $orderId): void
    {
        $this->seedStore();
        $this->seedOrder();

        assertFalse($this->scope->admitsJob($this->seedJob($orderId, self::STORE)));
    }

    /** @return list<array{0: string}> */
    public static function unusableOrderIds(): array
    {
        return [[''], ['0'], ['-1'], ['12 OR 1=1'], ['abc'], ['987000111x']];
    }

    /** An id that names no job at all. Fails closed, like every other refusal here. */
    public function testAnUnknownJobIsRefused(): void
    {
        assertFalse($this->scope->admitsJob(str_repeat('f', 32)));
    }

    /** @dataProvider malformedIds */
    public function testAMalformedIdIsRefusedWithoutQuerying(string $publicId): void
    {
        assertFalse($this->scope->admitsJob($publicId));
    }

    /** @return list<array{0: string}> */
    public static function malformedIds(): array
    {
        return [[''], ['short'], [str_repeat('f', 33)], ['../../etc/passwd'], ["' OR '1'='1"]];
    }

    // ------------------------------------------------------------------ seeding

    private function seedStore(): void
    {
        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => self::STORE,
            'name' => '__kf_test_scope_store__',
            'sync_hash' => str_repeat('b', 64),
            'synced_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
    }

    private function seedOrder(?int $store = null): void
    {
        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store ?? self::STORE,
            'source_order_id' => self::ORDER,
            'payload_json' => '{}',
            'content_hash' => str_repeat('c', 64),
            'synced_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
    }

    private function seedJob(?string $orderId, ?int $storeSourceId): string
    {
        $conversationPublicId = bin2hex(random_bytes(16));
        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => $conversationPublicId,
            'mode' => 'COMMON',
            'store_source_id' => $storeSourceId,
            'order_id' => $orderId,
            'uploaded_by_admin_id' => self::ADMIN_ID,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();

        $conversationId = (int) $this->connection->getLastInsertID();
        $jobPublicId = bin2hex(random_bytes(16));

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => $jobPublicId,
            'conversation_id' => $conversationId,
            'uploaded_by_admin_id' => self::ADMIN_ID,
            'status' => 'COMPLETED',
            'original_filename' => 'scope.wav',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();

        return $jobPublicId;
    }

    private function cleanup(): void
    {
        // Children first: the jobs' foreign key is what would otherwise refuse the conversations.
        $this->connection->createCommand()->delete(
            '{{%audio_transcription_jobs}}',
            ['uploaded_by_admin_id' => self::ADMIN_ID],
        )->execute();
        $this->connection->createCommand()->delete(
            '{{%audio_conversations}}',
            ['uploaded_by_admin_id' => self::ADMIN_ID],
        )->execute();
        IntegrationDb::cleanup($this->connection, '{{%order58_orders}}', ['source_order_id' => self::ORDER]);
        IntegrationDb::cleanup($this->connection, '{{%order58_stores}}', ['source_id' => self::STORE]);
        IntegrationDb::cleanup($this->connection, '{{%order58_stores}}', ['source_id' => self::STORE + 1]);
        IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['id' => self::ADMIN_ID]);
    }
}
