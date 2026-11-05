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

use function count;

use function gmdate;
use function str_repeat;

/**
 * Verifying an order through the page that offers it.
 *
 * The rules themselves are proved against MySQL in the integration suite; what is proved here is that
 * the page renders the control for the rows that can have one, that pressing it reaches the endpoint,
 * and that the endpoint refuses what it should when it is reached directly.
 *
 * Fixture store ids sit in the `9876559xx` range and cleanup is scoped to this suite's own rows.
 */
final class OrderTestingVerificationCest
{
    private const USERNAME = '__kf_ot_verify_admin__';
    private const PASSWORD = 'VerifyTestPassw0rd!secure';
    private const STORE_ID = 987655880;
    private const STORE_URL = '/order-testing/store/' . self::STORE_ID;
    private const STORE_NAME = 'Order Testing Verification Fixture Store';
    private const ORDER = 16800401;
    private const ORDER_B = 16800402;

    /** A recording whose order was never mirrored — the `#111` case from the live store. */
    private const ORPHAN_ORDER = 16800999;

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->seedStore();
        $this->seedOrder(self::ORDER);
        $this->seedOrder(self::ORDER_B);
        $this->seedRecording(self::ORDER, 'c', 'd');
        $this->seedRecording(self::ORDER_B, 'e', 'f');
        // Deliberately no seedOrder() for this one.
        $this->seedRecording(self::ORPHAN_ORDER, '1', '2');

        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ---------------------------------------------------------------- what the page offers

    /**
     * The Verification column is temporarily hidden (5 November 2026) — header, cells, the conversation
     * dialog's copy of the control and the confirmation dialog alike — while every other column stays,
     * one `<col>` per header so the table does not drift. See StoreAction::showsVerificationColumn().
     */
    public function theVerificationColumnIsTemporarilyHidden(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);
        $I->seeResponseCodeIs(200);

        $I->dontSee('Verification', 'thead');
        $I->dontSeeElement('[data-ot-verify]');
        $I->dontSeeElement('[data-ot-verify-dialog]');
        $I->dontSeeElement('#ot-verify-dialog');
        $I->dontSeeElement('col.ot-col-verification');

        foreach (['Order No#', 'Recording', 'Practice Order', 'Demo Orders', 'Set Topic'] as $header) {
            $I->see($header, '.ot-orders thead th');
        }

        // Still one column per header: a `<colgroup>` one short shifts every width a place left.
        Assert::assertCount(
            count($I->grabMultiple('.ot-orders > thead > tr > th')),
            $I->grabMultiple('.ot-orders > colgroup > col', 'class'),
        );
        Assert::assertSame(0, $this->stateRows(), 'rendering the page must not write anything');
    }

    // ---------------------------------------------------------------- the endpoint, unchanged

    public function verifyingAnOrderThroughTheEndpointStillSavesIt(WebTester $I): void
    {
        $this->decide($I, self::ORDER, 'verified', '0');

        $I->seeResponseCodeIs(200);
        Assert::assertTrue($this->isVerified(self::ORDER));

        // The decision is kept; the page simply does not draw it while the column is hidden.
        $I->amOnPage(self::STORE_URL);
        $I->dontSeeElement('.ot-verify__badge');
        Assert::assertTrue($this->isVerified(self::ORDER), 'rendering must not touch a kept decision');
    }

    public function verificationLeavesEveryOtherOrderAlone(WebTester $I): void
    {
        $this->decide($I, self::ORDER, 'verified', '0');

        Assert::assertTrue($this->isVerified(self::ORDER));
        Assert::assertFalse($this->isVerified(self::ORDER_B));
    }

    public function unverifyingPutsTheOrderBack(WebTester $I): void
    {
        $this->decide($I, self::ORDER, 'verified', '0');
        $this->decide($I, self::ORDER, 'unverified', (string) $this->versionOf(self::ORDER));

        $I->seeResponseCodeIs(200);
        Assert::assertFalse($this->isVerified(self::ORDER));
        Assert::assertSame(2, $this->eventRows(), 'both decisions are kept');
    }

    // ---------------------------------------------------------------- what the endpoint refuses

    public function anOrderFromAnotherStoreIsRefused(WebTester $I): void
    {
        $this->decide($I, 19999999, 'verified', '0');

        $I->seeResponseCodeIs(422);
        Assert::assertFalse($this->isVerified(19999999));
    }

    public function aRequestWithNoCsrfTokenIsRefused(WebTester $I): void
    {
        $I->sendAjaxPostRequest(
            self::STORE_URL . '/order/' . self::ORDER . '/verification',
            ['decision' => 'verified'],
        );

        // The global CSRF middleware runs before the router, so this never reaches the action at all.
        $I->seeResponseCodeIs(422);
        Assert::assertFalse($this->isVerified(self::ORDER));
    }

    public function anUnknownActionChangesNothing(WebTester $I): void
    {
        $this->decide($I, self::ORDER, 'destroy', '0');

        $I->seeResponseCodeIs(422);
        Assert::assertFalse($this->isVerified(self::ORDER));
        Assert::assertSame(0, $this->eventRows());
    }

    public function aStaleVersionIsRefusedRatherThanAppliedOverTheNewerChange(WebTester $I): void
    {
        $this->decide($I, self::ORDER, 'verified', '0');
        Assert::assertTrue($this->isVerified(self::ORDER));

        // A screen that still believes the order has never been touched tries to act on it.
        $this->decide($I, self::ORDER, 'unverified', '0');

        $I->seeResponseCodeIs(409);
        Assert::assertTrue($this->isVerified(self::ORDER), 'the newer decision must survive');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Posts one decision straight to the endpoint, as the table's form did, with a real CSRF token taken
     * from the store page. Asked for JSON, so the response code says what happened.
     */
    private function decide(WebTester $I, int $orderId, string $decision, string $version): void
    {
        $I->amOnPage(self::STORE_URL);
        $token = (string) $I->grabValueFrom('input[name="_csrf"]');

        $I->sendAjaxPostRequest(
            self::STORE_URL . '/order/' . $orderId . '/verification',
            ['_csrf' => $token, 'decision' => $decision, 'version' => $version],
        );
    }

    private function versionOf(int $orderId): int
    {
        $value = (new Query($this->connection))
            ->select('version')
            ->from('{{%order_testing_order_verifications}}')
            ->where(['store_source_id' => self::STORE_ID, 'source_order_id' => $orderId])
            ->scalar();

        return (int) $value;
    }

    private function isVerified(int $orderId): bool
    {
        $value = (new Query($this->connection))
            ->select('is_verified')
            ->from('{{%order_testing_order_verifications}}')
            ->where(['store_source_id' => self::STORE_ID, 'source_order_id' => $orderId])
            ->scalar();

        return (int) $value === 1;
    }

    private function stateRows(): int
    {
        return (int) (new Query($this->connection))
            ->from('{{%order_testing_order_verifications}}')
            ->where(['store_source_id' => self::STORE_ID])
            ->count();
    }

    private function eventRows(): int
    {
        return (int) (new Query($this->connection))
            ->from('{{%order_testing_verification_events}}')
            ->where(['store_source_id' => self::STORE_ID])
            ->count();
    }

    private function seedStore(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => self::STORE_ID,
            'name' => self::STORE_NAME,
            'active' => 1,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        // The store page resolves a store by joining its knowledge base, so a store row on its own is
        // invisible to it — a fixture without this lands on the picker instead of the page.
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => self::STORE_NAME,
            'slug' => 'kf-verify-' . self::STORE_ID,
            'source_system' => 'order58',
            'source_store_id' => self::STORE_ID,
            'source_name' => self::STORE_NAME,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedOrder(int $orderId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => self::STORE_ID,
            'source_order_id' => $orderId,
            'payload_json' => '{}',
            'content_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedRecording(int $orderId, string $conversationChar, string $jobChar): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $adminId = (int) (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::USERNAME])->scalar();

        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => str_repeat($conversationChar, 32),
            'store_source_id' => self::STORE_ID,
            'mode' => 'COMMON',
            'recording_type' => 'MIXED',
            'order_id' => (string) $orderId,
            'generate_ai_audio' => 0,
            'uploaded_by_admin_id' => $adminId,
            'created_at' => $now,
        ])->execute();

        $conversationRowId = (int) (new Query($this->connection))
            ->select('id')->from('{{%audio_conversations}}')
            ->where(['public_id' => str_repeat($conversationChar, 32)])->scalar();

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => str_repeat($jobChar, 32),
            'conversation_id' => $conversationRowId,
            'uploaded_by_admin_id' => $adminId,
            'original_filename' => 'verify.wav',
            'retained_audio_path' => 'source.wav',
            'source_role' => 'COMMON',
            'status' => 'NOT_REQUESTED',
            'processing_stage' => 'QUEUED',
            'transcription_provider' => 'WHISPER',
            'duration_seconds' => 12.5,
            'review_count' => 0,
            'created_at' => $now,
        ])->execute();
    }

    private function cleanup(): void
    {
        foreach ([
            '{{%order_testing_verification_events}}',
            '{{%order_testing_order_verifications}}',
        ] as $table) {
            $this->connection->createCommand()
                ->delete($table, ['store_source_id' => self::STORE_ID])->execute();
        }

        $conversationIds = (new Query($this->connection))
            ->select('id')->from('{{%audio_conversations}}')
            ->where(['store_source_id' => self::STORE_ID])->column();

        if ($conversationIds !== []) {
            $this->connection->createCommand()
                ->delete('{{%audio_transcription_jobs}}', ['conversation_id' => $conversationIds])->execute();
            $this->connection->createCommand()
                ->delete('{{%audio_conversations}}', ['id' => $conversationIds])->execute();
        }

        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%knowledge_bases}}', ['source_store_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_stores}}', ['source_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%admin_users}}', ['username' => self::USERNAME])->execute();
    }
}
