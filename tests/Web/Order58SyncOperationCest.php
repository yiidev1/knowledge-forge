<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function gmdate;
use function json_decode;
use function str_repeat;

/**
 * The per-operation progress panel and the endpoint it polls.
 *
 * ## What is really being guarded
 *
 * That a reader can tell THIS operation from the store's whole history. The all-orders bar underneath
 * answers "how much of this store has its items"; the panel answers "what is the sync I just started
 * doing". They are separate elements with separate numbers on purpose, and a change that merged them
 * would make both untrustworthy.
 *
 * And that the endpoint cannot report a finished operation while work is pending — the one guarantee
 * everything else rests on.
 *
 * **No provider call**: the panel and the endpoint only read rows.
 */
final class Order58SyncOperationCest
{
    private const ADMIN = '__kf_sync_op_admin__';
    private const PASSWORD = 'SyncOperationPassw0rd!secure';

    private const ORDERS_PAGE = '/admin/order58/orders';
    private const STATUS = '/admin/order58/orders/sync-status';

    private const STORE_ID = 987656020;
    private const STORE_NAME = 'Sync Operation Fixture Store';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->seedStore();
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ the panel

    /** No operation, no panel: an empty one would imply a sync nobody started. */
    public function theOrdersPageShowsNoOperationPanelWithoutABatch(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE . '?account_id=' . self::STORE_ID);

        $I->dontSeeElement('[data-o58-sync-batch]');
        $I->dontSee('This synchronisation');
    }

    public function theOperationPanelAppearsForTheBatchTheSyncReturned(WebTester $I): void
    {
        $batch = $this->seedBatch('orders_sync');
        $this->seedEnrolment($batch, 1, required: true);

        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE . '?account_id=' . self::STORE_ID . '&batch=' . $batch);

        $I->seeElement('[data-o58-sync-batch="' . $batch . '"]');
        $I->see('This synchronisation');
        $I->seeElement('progress.o58-sync-op__bar');
    }

    /** The two progress views stay distinct — different elements, different questions. */
    public function theOperationPanelAndTheAllOrdersBarAreSeparate(WebTester $I): void
    {
        $batch = $this->seedBatch('orders_sync');
        $this->seedEnrolment($batch, 1, required: true);
        $this->seedOrder(1, 'QUEUED');

        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE . '?account_id=' . self::STORE_ID . '&batch=' . $batch);

        // The unchanged bar, still answering its own question.
        $I->seeElement('.o58-item-sync');
        $I->see('Original order items');
        $I->see('stored orders of the selected store');

        // And this operation, answering a different one.
        $I->seeElement('.o58-sync-op');
        $I->see('This synchronisation');
    }

    /** The panel names the importer, because that is what an operator has to check when nothing moves. */
    public function thePanelSaysWhoActuallyFetchesTheItems(WebTester $I): void
    {
        $batch = $this->seedBatch('orders_sync');
        $this->seedEnrolment($batch, 1, required: true);

        $this->signIn($I);
        $I->amOnPage(self::ORDERS_PAGE . '?account_id=' . self::STORE_ID . '&batch=' . $batch);

        $I->see('systemd units have to be installed');
    }

    // ------------------------------------------------------------------ the endpoint

    /** Pending work can never poll as finished. */
    public function anOperationWithPendingWorkReportsRunning(WebTester $I): void
    {
        $batch = $this->seedBatch('orders_sync');
        $this->seedEnrolment($batch, 1, required: true);
        $this->seedEnrolment($batch, 2, required: false, outcome: 'SYNCED');

        $this->signIn($I);
        $data = $this->status($I, $batch);

        Assert::assertTrue($data['success']);
        Assert::assertSame('RUNNING', $data['outcome']);
        Assert::assertFalse($data['finished']);
        Assert::assertSame(1, $data['remaining']);
        Assert::assertLessThan(100, $data['percent'], 'Outstanding work must never read as 100%.');
    }

    public function anOperationWithEverythingSettledReportsCompleted(WebTester $I): void
    {
        $batch = $this->seedBatch('orders_sync');
        $this->seedEnrolment($batch, 1, required: false, outcome: 'SYNCED');
        $this->seedEnrolment($batch, 2, required: false, outcome: 'EMPTY');

        $this->signIn($I);
        $data = $this->status($I, $batch);

        Assert::assertSame('COMPLETED', $data['outcome']);
        Assert::assertTrue($data['finished']);
        Assert::assertSame(0, $data['remaining']);
        Assert::assertSame(100, $data['percent']);
    }

    public function aFailedEnrolmentMakesTheOperationPartial(WebTester $I): void
    {
        $batch = $this->seedBatch('orders_sync');
        $this->seedEnrolment($batch, 1, required: false, outcome: 'SYNCED');
        $this->seedEnrolment($batch, 2, required: false, outcome: 'FAILED');

        $this->signIn($I);
        $data = $this->status($I, $batch);

        Assert::assertSame('PARTIAL', $data['outcome']);
        Assert::assertSame(1, $data['failed']);
        Assert::assertTrue($data['finished'], 'A failure is a verdict, not an eternal wait.');
    }

    /** A scope cut short is reported as such, never as a completed range. */
    public function aTruncatedOrdersSyncIsReportedPartialAndSaysSo(WebTester $I): void
    {
        $batch = $this->seedBatch('orders_sync', truncated: true);
        $this->seedEnrolment($batch, 1, required: false, outcome: 'SYNCED');

        $this->signIn($I);
        $data = $this->status($I, $batch);

        Assert::assertSame('PARTIAL', $data['outcome']);
        Assert::assertTrue($data['truncated']);
    }

    /** An unknown or malformed batch is refused rather than guessed at. */
    public function anUnknownBatchIsNotFound(WebTester $I): void
    {
        $this->signIn($I);
        $I->sendAjaxGetRequest(self::STATUS . '?batch=999999999');

        $I->seeResponseCodeIs(404);
    }

    public function aMissingBatchParameterIsRefused(WebTester $I): void
    {
        $this->signIn($I);
        $I->sendAjaxGetRequest(self::STATUS);

        $I->seeResponseCodeIs(400);
    }

    public function aGuestCannotReadOperationProgress(WebTester $I): void
    {
        $batch = $this->seedBatch('orders_sync');

        $I->amOnPage(self::STATUS . '?batch=' . $batch);

        $I->seeCurrentUrlEquals('/login');
    }

    // ------------------------------------------------------------------ the recordings panel

    /**
     * The recordings page no longer carries the item-sync panel.
     *
     * It was removed on request. Asserted here rather than simply deleted, because a panel that fed
     * itself from a live batch is exactly the kind of thing that comes back: the batch still exists,
     * the endpoint it polled still answers, and the orders page still shows it — only this page does
     * not. Nothing is left behind either, no empty card and no heading with nothing under it.
     */
    public function theCallRecordingsPageDoesNotShowItemProgress(WebTester $I): void
    {
        $batch = $this->seedBatch('recording_sync');
        $this->seedEnrolment($batch, 1, required: true);

        $this->signIn($I);
        $I->amOnPage('/admin/order58/call-recordings?store=' . self::STORE_ID);

        $I->dontSeeElement('[data-o58-sync-batch]');
        $I->dontSeeElement('.o58-sync-op');
        $I->dontSee('Original order items');
        $I->dontSee('Separate from the recording downloads above');
        // The page's own purpose is untouched.
        $I->see('Order58 Call Recordings');
        $I->see('View Download History');
        $I->see("Find a store's calls");
        $I->see('Sync demo orders');
    }

    // ------------------------------------------------------------------ harness

    /** @return array<string, mixed> */
    private function status(WebTester $I, int $batch): array
    {
        $I->sendAjaxGetRequest(self::STATUS . '?batch=' . $batch);
        $I->seeResponseCodeIs(200);

        /** @var array<string, mixed> $data */
        $data = json_decode($I->grabPageSource(), true);

        return $data;
    }

    private function signIn(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
    }

    private function seedBatch(string $trigger, bool $truncated = false): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_item_sync_batches}}', [
            'store_account_id' => self::STORE_ID,
            'trigger_source' => $trigger,
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-02',
            'orders_received' => 2,
            'orders_saved' => 2,
            'eligible_count' => 2,
            'orders_truncated' => $truncated ? 1 : 0,
            'started_at' => $now,
            'outcome' => 'RUNNING',
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertID();
    }

    private function seedEnrolment(int $batch, int $orderId, bool $required, ?string $outcome = null): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_item_sync_batch_orders}}', [
            'batch_id' => $batch,
            'store_account_id' => self::STORE_ID,
            'source_order_id' => $orderId,
            'enrolled_state' => $required ? 'PENDING' : (string) $outcome,
            'required' => $required ? 1 : 0,
            'settled_at' => $required ? null : $now,
            'outcome' => $outcome,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedOrder(int $sourceOrderId, string $itemsStatus): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => self::STORE_ID,
            'source_order_id' => $sourceOrderId,
            'status' => 'completed',
            'payload_json' => '{}',
            'content_hash' => str_repeat('a', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'items_status' => $itemsStatus,
        ])->execute();
    }

    private function seedStore(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => self::STORE_ID,
            'name' => self::STORE_NAME,
            'company' => 'KFSO',
            'active' => 1,
            'snapshot_json' => '{"id":' . self::STORE_ID . ',"fields":{}}',
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => self::STORE_NAME,
            'slug' => 'kf-sync-op-' . self::STORE_ID,
            'source_system' => 'order58',
            'source_store_id' => self::STORE_ID,
            'source_name' => self::STORE_NAME,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** Scoped to this suite's own fixtures, never a blanket delete. */
    private function cleanup(): void
    {
        $adminIds = (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::ADMIN])->column();

        // Enrolments go with their batch through the foreign key.
        $this->connection->createCommand()
            ->delete('{{%order58_item_sync_batches}}', ['store_account_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_order_items}}', ['store_account_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => self::STORE_ID])->execute();

        if ($adminIds !== []) {
            $this->connection->createCommand()
                ->delete('{{%admin_users}}', ['id' => $adminIds])->execute();
        }

        $this->connection->createCommand()
            ->delete('{{%knowledge_bases}}', ['source_store_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_stores}}', ['source_id' => self::STORE_ID])->execute();
    }
}
