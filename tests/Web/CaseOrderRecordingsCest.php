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

use function file_get_contents;
use function getenv;
use function gmdate;
use function hash;
use function str_pad;
use function str_repeat;
use function strlen;
use function substr;
use function substr_count;
use function urlencode;

use const STR_PAD_LEFT;

/**
 * Case Order call recordings on Manage Orders and Practice Orders, and the endpoint that plays them.
 *
 * ## No test here can reach the provider
 *
 * Rendering tests never fetch audio — that is the point of them. The endpoint tests do fetch, so they
 * run only when the suite was started with `ORDER58_RECORDING_STREAM_SOURCE=fixture`, which the server
 * inherits and which makes it answer from `tests/_data/call-recordings` instead of the provider. Without
 * it they are skipped rather than allowed to send a request to an IP-allowlisted endpoint:
 *
 *     ORDER58_RECORDING_STREAM_SOURCE=fixture php vendor/bin/codecept run Web CaseOrderRecordingsCest
 *
 * Fixture stores are in the `9876559xx` range; every row here is this file's own and is removed after.
 */
final class CaseOrderRecordingsCest
{
    private const ADMIN = '__kf_case_recordings_admin__';
    private const PASSWORD = 'CaseRecordingsPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    private const STORE_ID = 987655996;
    private const STORE_NAME = 'Case Recording Fixture Store';
    private const OTHER_STORE_ID = 987655997;
    private const OTHER_STORE_NAME = 'Case Recording Fixture Other';

    /** One recording. */
    private const ORDER_ONE = 919900501;
    /** Three recordings in one call. */
    private const ORDER_THREE = 919900502;
    /** Imported, and never recorded. */
    private const ORDER_NONE = 919900503;
    /** Its fixture answers 404, the way the provider does for a link with nothing behind it. */
    private const ORDER_GONE = 919900504;

    private const SESSION_ONE = '919900601';
    private const SESSION_THREE = '919900602';
    private const SESSION_GONE = '919900404';

    /** What a real link carries in `name=`, so a test can prove it never reaches the page. */
    private const PHONE = '15550001111';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->insertStore(self::STORE_ID, self::STORE_NAME);
        $this->insertStore(self::OTHER_STORE_ID, self::OTHER_STORE_NAME);

        $this->order(self::STORE_ID, self::ORDER_ONE, 4);
        $this->order(self::STORE_ID, self::ORDER_THREE, 3);
        $this->order(self::STORE_ID, self::ORDER_NONE, 2);
        $this->order(self::STORE_ID, self::ORDER_GONE, 1);
        $this->order(self::OTHER_STORE_ID, self::ORDER_ONE, 1);

        $this->recordings(self::STORE_ID, self::ORDER_ONE, self::SESSION_ONE, 1);
        $this->recordings(self::STORE_ID, self::ORDER_THREE, self::SESSION_THREE, 3);
        $this->recordings(self::STORE_ID, self::ORDER_GONE, self::SESSION_GONE, 1);
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------------ Manage Orders

    public function theRecordingColumnOffersPlayAndDownloadIcons(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $I->seeResponseCodeIs(200);

        $id = $this->recordingId(self::SESSION_ONE, 0);
        $play = '/order-testing/store/' . self::STORE_ID . '/order/' . self::ORDER_ONE . '/recording/' . $id;

        $I->seeElement('button.a2t-play[data-a2t-play="' . $play . '"]');
        $I->seeElement('a.ot-rec__download[href="' . $play . '?download=1"]');
        // Icons, with their words for assistive technology and a tooltip — never as visible text.
        $I->seeElement('button[aria-label="Play the call recording of order #' . self::ORDER_ONE . '"]');
        $I->seeElement('a[title="Download the call recording of order #' . self::ORDER_ONE . '"]');
    }

    public function severalRecordingsAreOneDisclosureEachWithItsOwnControls(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $I->see('3 recordings', 'details.ot-rec__more summary');

        foreach ([1, 2, 3] as $n) {
            $I->seeElement('button[aria-label="Play recording ' . $n . ' of 3 of order #' . self::ORDER_THREE . '"]');
            $I->seeElement('a[title="Download recording ' . $n . ' of 3 of order #' . self::ORDER_THREE . '"]');
        }
    }

    /** The provider's URL holds the customer's phone number: it must not be anywhere in the page. */
    public function thePageNeverContainsTheProvidersLink(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $html = $I->grabPageSource();

        Assert::assertStringNotContainsString('xrainbow', $html);
        Assert::assertStringNotContainsString(self::PHONE, $html);
        Assert::assertStringNotContainsString('wav_download_urls', $html);
    }

    public function anOrderWithoutARecordingStillShowsTheDash(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        // Four orders, three with recordings: exactly one dash in the Recording column.
        Assert::assertSame(1, substr_count($I->grabPageSource(), 'ot-rec__state ot-rec__state--none'));
    }

    /** A link the provider replaced is kept in the table but no longer offered. */
    public function aReplacedLinkIsNotOffered(WebTester $I): void
    {
        $replaced = $this->recordingId(self::SESSION_ONE, 0);
        $this->connection->createCommand()
            ->update('{{%order58_call_recordings}}', ['delisted_at' => gmdate('Y-m-d H:i:s')], ['id' => $replaced])
            ->execute();

        $this->signIn($I);
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $I->dontSeeElement('[data-a2t-play$="/recording/' . $replaced . '"]');
    }

    /** Manage Orders keeps its three data-source chips; the new one belongs to Practice Orders. */
    public function theManageOrdersPickerIsUnchanged(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/order-testing?q=' . urlencode('Case Recording Fixture'));

        $I->see('Order58 Case Orders', '.filter-chip');
        $I->dontSee('Case Order recordings', '.filter-chip');
    }

    // ------------------------------------------------------------------------ Practice Orders

    /**
     * **An order whose only audio is a Case Order recording link is practisable.**
     *
     * Nothing for this store is in `audio_conversations`; the order is listed because its recording is.
     * The order with nothing to listen to is not.
     */
    public function practiceOrdersListsOrdersWithOnlyARecordingLink(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);
        $I->seeResponseCodeIs(200);

        $I->see('#' . self::ORDER_ONE);
        $I->see('#' . self::ORDER_THREE);
        $I->dontSee('#' . self::ORDER_NONE);

        $id = $this->recordingId(self::SESSION_ONE, 0);
        // The same route as Manage Orders: Practice Orders is in the same route group.
        $I->seeElement('[data-a2t-play="/order-testing/store/' . self::STORE_ID . '/order/'
            . self::ORDER_ONE . '/recording/' . $id . '"]');
    }

    /**
     * **The card counts ORDERS with a recording** — not links, not every order.
     *
     * Four orders: one link, three links, none, one link. So 3 orders with recordings, behind 3 calls
     * and 5 files — and the note names the calls and files because they differ from the headline.
     */
    public function thePracticePickerCountsOrdersWithRecordings(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/practice-orders?audio=case-recordings&q=' . urlencode(self::STORE_NAME));

        $I->see(self::STORE_NAME, '.store-card__name');
        $I->see('Orders With Recordings', '.store-card__metric-label');
        $I->see('3', '.store-card__metric-value');
        $I->see('3 calls · 5 recordings', '.store-card__metric-note');
    }

    /** A store whose orders have no recording link is not listed under the filter. */
    public function thePracticePickerHidesAStoreWithoutRecordingLinks(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/practice-orders?audio=case-recordings&q=' . urlencode(self::OTHER_STORE_NAME));

        $I->dontSee(self::OTHER_STORE_NAME, '.store-card__name');
    }

    /**
     * **Practice Orders lands on the Case Order recordings**, and Uploaded audio is one click away.
     *
     * The bare address — the one the menu links to — is that view, so the chip that leaves it is the
     * one that has to carry a parameter.
     */
    public function thePracticePickerLandsOnCaseOrderRecordings(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/practice-orders?q=' . urlencode(self::STORE_NAME));

        $I->see('Case Order recordings', '.filter-chip--active');
        $I->see(self::STORE_NAME, '.store-card__name');
        $I->see('Orders With Recordings', '.store-card__metric-label');
        $I->seeElement('a.filter-chip[href*="audio=with"]');
    }

    // ------------------------------------------------------------------------ the endpoint

    public function aRecordingStreamsAsAWavFile(WebTester $I): void
    {
        $this->requireFixtureSource($I);
        $this->signIn($I);
        $I->amOnPage($this->recordingUrl(self::STORE_ID, self::ORDER_ONE, $this->recordingId(self::SESSION_ONE, 0)));

        $I->seeResponseCodeIs(200);
        $body = $I->grabPageSource();
        Assert::assertSame('RIFF', substr($body, 0, 4));
        Assert::assertSame(strlen($this->fixtureWav()), strlen($body), 'the whole file');
    }

    public function aByteRangeIsAnsweredWith206AndJustThoseBytes(WebTester $I): void
    {
        $this->requireFixtureSource($I);
        $this->signIn($I);

        try {
            $I->haveHttpHeader('Range', 'bytes=0-3');
            $I->amOnPage($this->recordingUrl(self::STORE_ID, self::ORDER_ONE, $this->recordingId(self::SESSION_ONE, 0)));

            $I->seeResponseCodeIs(206);
            Assert::assertSame('RIFF', $I->grabPageSource());

            $I->haveHttpHeader('Range', 'bytes=40-');
            $I->amOnPage($this->recordingUrl(self::STORE_ID, self::ORDER_ONE, $this->recordingId(self::SESSION_ONE, 0)));
            $I->seeResponseCodeIs(206);
            Assert::assertSame(substr($this->fixtureWav(), 40), $I->grabPageSource(), 'a seek mid-file');

            $I->haveHttpHeader('Range', 'bytes=' . (strlen($this->fixtureWav()) + 10) . '-');
            $I->amOnPage($this->recordingUrl(self::STORE_ID, self::ORDER_ONE, $this->recordingId(self::SESSION_ONE, 0)));
            $I->seeResponseCodeIs(416);
        } finally {
            $I->deleteHeader('Range');
        }
    }

    public function aDownloadIsTheSameFile(WebTester $I): void
    {
        $this->requireFixtureSource($I);
        $this->signIn($I);
        $I->amOnPage($this->recordingUrl(self::STORE_ID, self::ORDER_ONE, $this->recordingId(self::SESSION_ONE, 0)) . '?download=1');

        $I->seeResponseCodeIs(200);
        Assert::assertSame($this->fixtureWav(), $I->grabPageSource());
    }

    /**
     * **A recording is reachable only through its own order of its own store.**
     *
     * The same id under another order, under another store's copy of the same order number, or an id
     * that does not exist, are all the same 404 — and none of them fetch anything.
     */
    public function aRecordingIdCannotBeReplayedAgainstAnotherOrderOrStore(WebTester $I): void
    {
        $this->signIn($I);
        $id = $this->recordingId(self::SESSION_ONE, 0);

        $I->amOnPage($this->recordingUrl(self::STORE_ID, self::ORDER_THREE, $id));
        $I->seeResponseCodeIs(404);

        $I->amOnPage($this->recordingUrl(self::OTHER_STORE_ID, self::ORDER_ONE, $id));
        $I->seeResponseCodeIs(404);

        $I->amOnPage($this->recordingUrl(self::STORE_ID, self::ORDER_ONE, $id + 1_000_000));
        $I->seeResponseCodeIs(404);
    }

    /** The provider has nothing at the link: a plain-text 404 a person can read, not a broken player. */
    public function anUnavailableRecordingIsAReadableRefusal(WebTester $I): void
    {
        $this->requireFixtureSource($I);
        $this->signIn($I);
        $I->amOnPage($this->recordingUrl(self::STORE_ID, self::ORDER_GONE, $this->recordingId(self::SESSION_GONE, 0)));

        $I->seeResponseCodeIs(404);
        $I->see('Order58 has no recording at this link');
        Assert::assertStringNotContainsString('xrainbow', $I->grabPageSource());
    }

    public function aSignedOutReaderGetsNoAudio(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage($this->recordingUrl(self::STORE_ID, self::ORDER_ONE, $this->recordingId(self::SESSION_ONE, 0)));

        $I->seeCurrentUrlEquals('/login');
    }

    // ------------------------------------------------------------------------ fixtures

    private function requireFixtureSource(WebTester $I): void
    {
        if (getenv('ORDER58_RECORDING_STREAM_SOURCE') !== 'fixture') {
            $I->markTestSkipped(
                'Fetches a recording, so it needs ORDER58_RECORDING_STREAM_SOURCE=fixture — without it the '
                . 'server would ask the real, IP-allowlisted provider.',
            );
        }
    }

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    private function recordingUrl(int $store, int $order, int $recordingId): string
    {
        return '/order-testing/store/' . $store . '/order/' . $order . '/recording/' . $recordingId;
    }

    private function fixtureWav(): string
    {
        return (string) file_get_contents(__DIR__ . '/../_data/call-recordings/default.wav');
    }

    private function recordingId(string $session, int $position): int
    {
        return (int) (new Query($this->connection))
            ->select('r.id')
            ->from(['r' => '{{%order58_call_recordings}}'])
            ->innerJoin(['s' => '{{%order58_call_sessions}}'], 's.id = r.session_id')
            ->where([
                's.store_source_id' => self::STORE_ID,
                's.call_session_id' => $session,
                'r.position' => $position,
            ])
            ->scalar();
    }

    private function insertStore(int $sourceId, string $name): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => $name,
            'active' => 1,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => $name,
            'slug' => 'kf-case-recordings-' . $sourceId,
            'source_system' => 'order58',
            'source_store_id' => $sourceId,
            'source_name' => $name,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** @param int $age orders are listed newest first; a higher age sorts lower */
    private function order(int $store, int $order, int $age): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $order,
            'payload_json' => '{}',
            'content_hash' => str_pad((string) $order, 64, '0', STR_PAD_LEFT),
            'source_created_at' => 1_790_000_000 - $age * 60,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function recordings(int $store, int $order, string $session, int $count): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_call_sessions}}', [
            'store_source_id' => $store,
            'source_order_id' => $order,
            'call_session_id' => $session,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $sessionRow = (int) $this->connection->getLastInsertID();

        for ($position = 0; $position < $count; ++$position) {
            $url = 'https://order58.xrainbow.com/api/external/recording/fetch/' . $session
                . '?time=2026-10-27&company=SWCC&name=' . $order . '-' . self::PHONE . '-' . $session
                . ($position > 0 ? '-' . $position : '');

            $this->connection->createCommand()->insert('{{%order58_call_recordings}}', [
                'session_id' => $sessionRow,
                'position' => $position,
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();
        }
    }

    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%admin_users}}', ['username' => self::ADMIN])->execute();

        $stores = [self::STORE_ID, self::OTHER_STORE_ID];

        // Sessions and their links cascade from the orders.
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => $stores])->execute();
        $this->connection->createCommand()
            ->delete('{{%knowledge_bases}}', ['source_store_id' => $stores])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_stores}}', ['source_id' => $stores])->execute();
    }
}
