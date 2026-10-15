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

    public function theTableCarriesAVerificationColumn(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);
        $I->seeResponseCodeIs(200);

        $I->see('Verification', 'thead');
        $I->seeElement('[data-ot-verify][data-ot-order="' . self::ORDER . '"]');
        $I->seeElement('[data-ot-verify][data-ot-order="' . self::ORDER . '"] [data-ot-verify-button]');
    }

    public function everyOrderStartsUnverified(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);

        $I->seeElement('[data-ot-verify][data-ot-order="' . self::ORDER . '"][data-ot-verified="0"]');
        Assert::assertSame(0, $this->stateRows(), 'rendering the column must not write anything');
    }

    public function aRowWithNoSourceOrderOffersNothingToVerify(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);

        // The recording is listed — it is still a recording — but it carries no control, exactly as
        // the Set Topic column already behaves for the same rows.
        $I->see((string) self::ORPHAN_ORDER);
        $I->dontSeeElement('[data-ot-verify][data-ot-order="' . self::ORPHAN_ORDER . '"]');
    }

    // ---------------------------------------------------------------- the round trip

    public function verifyingAnOrderSavesItAndShowsTheBadge(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);
        $I->submitForm('[data-ot-verify][data-ot-order="' . self::ORDER . '"]', []);

        $I->see('Order verified successfully.', '.alert');
        Assert::assertTrue($this->isVerified(self::ORDER));

        // And the page now renders the other state.
        $I->amOnPage(self::STORE_URL);
        $I->seeElement('[data-ot-verify][data-ot-order="' . self::ORDER . '"][data-ot-verified="1"]');
        $I->see('Verified', '.ot-verify__badge');
    }

    public function verificationSurvivesAReloadAndLeavesEveryOtherOrderAlone(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);
        $I->submitForm('[data-ot-verify][data-ot-order="' . self::ORDER . '"]', []);

        $I->amOnPage(self::STORE_URL);
        $I->seeElement('[data-ot-verify][data-ot-order="' . self::ORDER . '"][data-ot-verified="1"]');
        $I->seeElement('[data-ot-verify][data-ot-order="' . self::ORDER_B . '"][data-ot-verified="0"]');
    }

    public function unverifyingPutsTheOrderBack(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);
        $I->submitForm('[data-ot-verify][data-ot-order="' . self::ORDER . '"]', []);

        // The control now posts the other direction, and the page is what decides which.
        $I->amOnPage(self::STORE_URL);
        $I->submitForm('[data-ot-verify][data-ot-order="' . self::ORDER . '"]', []);

        $I->see('Order verification removed successfully.', '.alert');
        Assert::assertFalse($this->isVerified(self::ORDER));
        Assert::assertSame(2, $this->eventRows(), 'both decisions are kept');
    }

    // ---------------------------------------------------------------- what the endpoint refuses

    public function anOrderFromAnotherStoreIsRefused(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);

        // A store this administrator may reach, and an order number that is not one of its orders.
        $I->submitForm('[data-ot-verify][data-ot-order="' . self::ORDER . '"]', [], null);
        $I->amOnPage(self::STORE_URL);

        $I->sendAjaxPostRequest(
            self::STORE_URL . '/order/19999999/verification',
            ['decision' => 'verified', 'version' => '0'],
        );

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
        $I->amOnPage(self::STORE_URL);
        $I->submitForm('[data-ot-verify][data-ot-order="' . self::ORDER . '"]', ['decision' => 'destroy']);

        Assert::assertFalse($this->isVerified(self::ORDER));
        Assert::assertSame(0, $this->eventRows());
    }

    public function aStaleVersionIsRefusedRatherThanAppliedOverTheNewerChange(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);
        $I->submitForm('[data-ot-verify][data-ot-order="' . self::ORDER . '"]', []);
        Assert::assertTrue($this->isVerified(self::ORDER));

        // A screen that still believes the order has never been touched tries to act on it.
        $I->amOnPage(self::STORE_URL);
        $I->submitForm(
            '[data-ot-verify][data-ot-order="' . self::ORDER . '"]',
            ['version' => '0', 'decision' => 'unverified'],
        );

        $I->see('changed by somebody else', '.alert');
        Assert::assertTrue($this->isVerified(self::ORDER), 'the newer decision must survive');
    }

    // ---------------------------------------------------------------- the conversation dialog

    public function theDialogCarriesItsOwnVerificationControl(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);

        // Present in the markup and hidden, so it cannot be seen before the script decides which order
        // the dialog is about. The script fills its label; the server renders no state into it, which
        // is what keeps the table the only place verification is recorded.
        $I->seeElement('[data-ot-verify-dialog]');
        $I->seeElement('#ot-verify-dialog [data-ot-verify-confirm]');
    }

    // ---------------------------------------------------------------- helpers

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
