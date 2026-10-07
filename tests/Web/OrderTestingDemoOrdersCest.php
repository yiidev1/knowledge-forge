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

use function bin2hex;
use function gmdate;
use function hash;
use function json_decode;
use function json_encode;
use function random_bytes;
use function str_repeat;
use function time;

/**
 * Order Testing's demo orders: the column, the list, the comparison and who is recorded as testing.
 *
 * ## What is really being guarded
 *
 * The store-scoping. Three ids identify a demo order and all three come out of the URL, so the thing
 * worth proving over HTTP — not in a unit test — is that changing one of them reaches nothing. A
 * comparison page that answered for another merchant's order would be a data leak with a plausible URL.
 *
 * And the attribution. The Demo URL button stopped being a link so that who clicked it could be
 * recorded; a test that only checked the redirect would pass with the row never written.
 */
final class OrderTestingDemoOrdersCest
{
    private const ADMIN = '__kf_ot_demo_admin__';
    private const PASSWORD = 'OrderTestingDemoPassw0rd!secure';

    private const STORE_ID = 987655910;
    private const OTHER_STORE_ID = 987655911;
    private const STORE_NAME = 'Order Testing Demo Fixture Store';
    private const HOST = 'kf-demo-fixture.order58.com';

    private const SOURCE_ORDER = 16758551;
    private const QUIET_ORDER = 16754561;
    private const PHONE = '19178532637';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->seedStore(self::STORE_ID, self::STORE_NAME);
        $this->seedStore(self::OTHER_STORE_ID, self::STORE_NAME . ' (other)');
        $this->seedSourceOrder(self::STORE_ID, self::SOURCE_ORDER);
        $this->seedSourceOrder(self::STORE_ID, self::QUIET_ORDER);
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ the column

    /** The new column sits beside Demo URL and says nothing has been created yet. */
    public function theStorePageShowsADashWhenNothingHasBeenCreated(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $I->seeResponseCodeIs(200);

        $I->see('Demo URL');
        $I->see('Demo Orders');
        // No link to a page that would be empty.
        $I->dontSeeElement('a[href*="/demo-orders"]');
    }

    /** Three demo orders, one link, the count in it. */
    public function theStorePageCountsTheDemoOrders(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);
        $this->seedDemoOrder(16630651);
        $this->seedDemoOrder(16630661);
        $this->seedDemoOrder(16630671);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $I->see('View (3)');
        $I->seeElement(
            'a[href="/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo-orders"]',
        );
    }

    /** The audio page is untouched: it has neither the column nor the form. */
    public function theAudioStorePageHasNoDemoOrdersColumn(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);
        $this->seedDemoOrder(16630651);

        $I->amOnPage('/admin/order58/store-audio');
        $I->amOnPage('/audio-to-text/store/' . self::STORE_ID);
        $I->seeResponseCodeIs(200);

        $I->see('Demo URL');
        $I->dontSee('Demo Orders');
        $I->dontSeeElement('form[action*="/demo-url"]');
    }

    // ------------------------------------------------------------------ the list

    public function theListShowsOnlyThatSourceOrdersDemoOrders(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);
        $this->seedDemoOrder(16630661);
        // A demo order of a DIFFERENT source order in the same store.
        $this->seedDemoOrder(16630681, self::QUIET_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo-orders');
        $I->seeResponseCodeIs(200);

        $I->see('16630651');
        $I->see('16630661');
        $I->dontSee('16630681');
    }

    /** Each demo order gets its own comparison; they are never merged into one. */
    public function eachDemoOrderLinksToItsOwnComparison(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);
        $this->seedDemoOrder(16630661);
        $this->seedDemoOrder(16630671);

        $base = '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER;
        $I->amOnPage($base . '/demo-orders');

        foreach ([16630651, 16630661, 16630671] as $demoId) {
            $I->seeElement('a[href="' . $base . '/demo/' . $demoId . '"]');
        }
    }

    /** Attribution is shown only where a real attempt backs it. */
    public function anUnmatchedDemoOrderIsLabelledUnmatched(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo-orders');

        // Scoped to the table: the admin layout prints the signed-in username in the sidebar, so an
        // unscoped assertion here would be testing the chrome rather than the attribution cell.
        $I->see('Unmatched', 'table');
        $I->dontSee(self::ADMIN, 'table');
    }

    public function aMatchedDemoOrderNamesTheAdministrator(WebTester $I): void
    {
        $this->signIn($I);
        $attemptId = $this->seedAttempt();
        $this->seedDemoOrder(16630651, self::SOURCE_ORDER, $attemptId);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo-orders');

        $I->see(self::ADMIN, 'table');
    }

    // ------------------------------------------------------------------ the comparison

    public function theComparisonShowsBothOrdersSideBySide(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/16630651',
        );
        $I->seeResponseCodeIs(200);

        $I->see('Original order');
        $I->see('Demo / test order');
        $I->see('Customer');
        $I->see('Totals');
        $I->see('Order items');
        // Both halves really rendered, not just the headings.
        $I->see('Wonton Soup');
    }

    /** The fixture demo order differs from the source on purpose; the page must say so. */
    public function theComparisonReportsADifferenceItWasGiven(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651, self::SOURCE_ORDER, null, '9.99000');

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/16630651',
        );

        $I->see('Different');
        $I->see('9.99000');
        $I->see('4.28000');
    }

    /** No score, anywhere on the page. */
    public function theComparisonShowsNoScore(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/16630651',
        );

        // Not the WORD — this page says in prose that it calculates no accuracy percentage, and a
        // test banning the word would ban saying so. What must not appear is a verdict.
        $I->see('These are differences, not a score');
        // Structural, not lexical: this page says in prose that it calculates no accuracy percentage
        // or pass mark, so banning those words would ban saying so. What must not exist is a rendered
        // verdict. `OrderTestingBoundariesTest` is the stronger guard, over the source itself.
        $I->dontSeeElement('[data-ot-score]');
        $I->dontSeeElement('.ot-score');
    }

    /** A demo order whose source order was never mirrored stays fully visible. */
    public function aDemoOrderSurvivesAMissingSourceOrder(WebTester $I): void
    {
        $this->signIn($I);
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => self::STORE_ID])->execute();
        $this->seedDemoOrder(16630651);

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/16630651',
        );
        $I->seeResponseCodeIs(200);

        $I->see('Source order unavailable in the local mirror');
        // The trainee's work is still all there.
        $I->see('Wonton Soup');
        $I->see('Not comparable');
    }

    // ------------------------------------------------------------------ the boundary

    public function aDemoOrderCannotBeReachedThroughAnotherStore(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);

        $I->amOnPage(
            '/order-testing/store/' . self::OTHER_STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/16630651',
        );
        $I->seeResponseCodeIs(404);
    }

    public function aDemoOrderCannotBeReachedThroughAnotherSourceOrder(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedDemoOrder(16630651);

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::QUIET_ORDER . '/demo/16630651',
        );
        $I->seeResponseCodeIs(404);
    }

    public function anUnknownDemoOrderIsNotFound(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(
            '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER . '/demo/99999999',
        );
        $I->seeResponseCodeIs(404);
    }

    public function aGuestSeesNoneOfIt(WebTester $I): void
    {
        $base = '/order-testing/store/' . self::STORE_ID . '/order/' . self::SOURCE_ORDER;

        foreach ([$base . '/demo-orders', $base . '/demo/16630651'] as $path) {
            $I->amOnPage($path);
            $I->seeCurrentUrlEquals('/login');
        }
    }

    // ------------------------------------------------------------------ the Demo URL click

    /**
     * The JSON answer the page script reads: the attempt is written, then the URL is handed back.
     *
     * No redirect, and that is the fix rather than an accident. `form-action 'self'` is enforced
     * across redirects by Chrome, so a 303 to order58.com left the new tab on about:blank. A URL the
     * script navigates a tab to is not a form submission and is not restricted.
     */
    public function theJsonEndpointRecordsTheAttemptAndReturnsTheUrl(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $token = $I->grabAttributeFrom('form[action*="/demo-url"] input[name="_csrf"]', 'value');

        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest('/order-testing/store/' . self::STORE_ID . '/demo-url', [
            'source_order_id' => (string) self::SOURCE_ORDER,
            '_csrf' => $token,
        ]);

        $I->seeResponseCodeIs(200);

        // Decoded, not matched as text. `json_encode` escapes forward slashes here — the house style
        // keeps that — so a substring assertion would be testing the encoder's escaping rather than
        // the URL. Built server-side from the mirrored host and phone, through the host allow-list.
        Assert::assertSame(
            ['url' => 'https://' . self::HOST . '/admin/demo/order/make/'
                . self::SOURCE_ORDER . '-' . self::PHONE],
            $this->payload($I),
        );

        $row = (new Query($this->connection))
            ->select(['initiated_by_type', 'initiated_by_id', 'status'])
            ->from('{{%order_testing_attempts}}')
            ->where(['store_source_id' => self::STORE_ID, 'source_order_id' => self::SOURCE_ORDER])
            ->one();

        Assert::assertIsArray($row, 'the attempt must be written before the URL is handed back');
        Assert::assertSame('ADMIN', (string) $row['initiated_by_type']);
        Assert::assertSame('STARTED', (string) $row['status']);
        Assert::assertSame($this->adminId(), (int) $row['initiated_by_id']);
    }

    /**
     * Nothing in the request body can choose the destination.
     *
     * Without this the endpoint would be an open redirect that hands a customer's phone number to
     * whoever picked the host.
     */
    public function noPostedFieldCanChangeTheDestination(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $token = $I->grabAttributeFrom('form[action*="/demo-url"] input[name="_csrf"]', 'value');

        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest('/order-testing/store/' . self::STORE_ID . '/demo-url', [
            'source_order_id' => (string) self::SOURCE_ORDER,
            '_csrf' => $token,
            'url' => 'https://evil.example.com/steal',
            'host' => 'evil.example.com',
            'redirect' => 'https://evil.example.com/steal',
            'return_to' => 'https://evil.example.com/steal',
        ]);

        $I->seeResponseCodeIs(200);

        $payload = $this->payload($I);

        Assert::assertSame(
            'https://' . self::HOST . '/admin/demo/order/make/' . self::SOURCE_ORDER . '-' . self::PHONE,
            $payload['url'] ?? null,
        );
        $I->dontSeeInSource('evil.example.com');
    }

    /**
     * The actor cannot be chosen by the browser.
     *
     * The identity comes from the authenticated session; a posted `initiated_by_id` is ignored, which
     * is what stops one operator attributing their work to somebody else.
     */
    public function thePostedActorIdIsIgnored(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $token = $I->grabAttributeFrom('form[action*="/demo-url"] input[name="_csrf"]', 'value');

        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest('/order-testing/store/' . self::STORE_ID . '/demo-url', [
            'source_order_id' => (string) self::SOURCE_ORDER,
            '_csrf' => $token,
            'initiated_by_id' => '999999',
            'initiated_by_type' => 'OPERATION',
        ]);

        $row = (new Query($this->connection))
            ->select(['initiated_by_type', 'initiated_by_id'])
            ->from('{{%order_testing_attempts}}')
            ->where(['store_source_id' => self::STORE_ID, 'source_order_id' => self::SOURCE_ORDER])
            ->one();

        Assert::assertIsArray($row);
        Assert::assertSame($this->adminId(), (int) $row['initiated_by_id']);
        Assert::assertSame('ADMIN', (string) $row['initiated_by_type']);
    }

    /** A second holder is a 409, so the script closes its blank tab rather than leaving it open. */
    public function aConflictingAttemptIsRefusedWithoutASecondRow(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        // Somebody else already holds this order.
        $this->connection->createCommand()->insert('{{%order_testing_attempts}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'store_source_id' => self::STORE_ID,
            'source_order_id' => self::SOURCE_ORDER,
            'initiated_by_type' => 'ADMIN',
            'initiated_by_id' => $this->adminId() + 9_000_000,
            'status' => 'STARTED',
            'started_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $token = $I->grabAttributeFrom('form[action*="/demo-url"] input[name="_csrf"]', 'value');

        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest('/order-testing/store/' . self::STORE_ID . '/demo-url', [
            'source_order_id' => (string) self::SOURCE_ORDER,
            '_csrf' => $token,
        ]);

        $I->seeResponseCodeIs(409);
        $I->seeInSource('already testing this order');
        // No URL on a refusal: the script must have nothing to navigate its tab to.
        $I->dontSeeInSource('/admin/demo/order/make/');

        Assert::assertSame(1, (int) (new Query($this->connection))
            ->from('{{%order_testing_attempts}}')
            ->where(['store_source_id' => self::STORE_ID, 'source_order_id' => self::SOURCE_ORDER])
            ->count());
    }

    /** An order of another store cannot be started from this store's page. */
    public function anOrderOfAnotherStoreCannotBeStarted(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $token = $I->grabAttributeFrom('form[action*="/demo-url"] input[name="_csrf"]', 'value');

        // The order exists, but under STORE_ID — asked for as OTHER_STORE_ID it must not resolve.
        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest('/order-testing/store/' . self::OTHER_STORE_ID . '/demo-url', [
            'source_order_id' => (string) self::SOURCE_ORDER,
            '_csrf' => $token,
        ]);

        $I->seeResponseCodeIs(422);
        $I->dontSeeInSource('/admin/demo/order/make/');

        Assert::assertSame(0, (int) (new Query($this->connection))
            ->from('{{%order_testing_attempts}}')
            ->where(['store_source_id' => self::OTHER_STORE_ID])
            ->count());
    }

    /**
     * The no-JavaScript path still works, and still records the attempt.
     *
     * The form's `target="_blank"` opens the tab; this page renders in it with a plain link, which is
     * not a form submission and so is not caught by `form-action`.
     */
    public function withoutJavaScriptTheFormAnswersWithALinkPage(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);
        $I->submitForm('form[action="/order-testing/store/' . self::STORE_ID . '/demo-url"]', []);

        $I->seeResponseCodeIs(200);
        $I->see('continue to Order58');
        $I->seeElement(
            'a[href="https://' . self::HOST . '/admin/demo/order/make/'
            . self::SOURCE_ORDER . '-' . self::PHONE . '"]',
        );

        Assert::assertSame(1, (int) (new Query($this->connection))
            ->from('{{%order_testing_attempts}}')
            ->where(['store_source_id' => self::STORE_ID])
            ->count());
    }

    /** Without CSRF the click writes nothing. */
    public function theDemoUrlPostRequiresCsrf(WebTester $I): void
    {
        $this->signIn($I);

        $I->sendAjaxPostRequest('/order-testing/store/' . self::STORE_ID . '/demo-url', [
            'source_order_id' => (string) self::SOURCE_ORDER,
        ]);

        Assert::assertSame(0, (int) (new Query($this->connection))
            ->from('{{%order_testing_attempts}}')
            ->where(['store_source_id' => self::STORE_ID])
            ->count());
    }

    /** The markup the script binds to, so a rename cannot silently unhook it. */
    public function theDemoUrlButtonCarriesItsScriptHook(WebTester $I): void
    {
        $this->signIn($I);
        $this->seedCall(self::SOURCE_ORDER);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $I->seeElement('form[action="/order-testing/store/' . self::STORE_ID . '/demo-url"]');
        $I->seeElement('button[data-ot-demo-url]');
        $I->seeElement('[data-ot-demo-message]');
        // The script that binds them is loaded, and is this surface's own file.
        $I->seeElement('script[src*="order-testing"]');
    }

    // ------------------------------------------------------------------ fixtures

    /**
     * The JSON body, decoded.
     *
     * @return array<string, mixed>
     */
    private function payload(WebTester $I): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($I->grabPageSource(), true);

        Assert::assertIsArray($decoded, 'the endpoint must answer with a JSON object');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function signIn(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
    }

    private function adminId(): int
    {
        return (int) (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::ADMIN])->scalar();
    }

    private function seedStore(int $sourceId, string $name): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $sourceId,
            'name' => $name,
            'active' => 1,
            'host' => self::HOST,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        // The store page resolves a store through `knowledge_bases`, not `order58_stores` — the name
        // it shows is that table's. Seeding only the mirror sent every store-page test to the picker,
        // which is what the first run of this suite actually proved.
        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => $name,
            'slug' => 'kf-ot-demo-' . $sourceId,
            'source_system' => 'order58',
            'source_store_id' => $sourceId,
            'source_name' => $name,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /** The mirrored ORIGINAL order — the left-hand side of every comparison. */
    private function seedSourceOrder(int $storeId, int $orderId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $payload = (string) json_encode($this->orderDocument($orderId, '4.28000'));

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $storeId,
            'source_order_id' => $orderId,
            'reservation_phone' => self::PHONE,
            'type' => 'delivery',
            'payment_method' => 'cash',
            'status' => 'completed',
            'total_amount' => '4.28000',
            'payload_json' => $payload,
            'content_hash' => hash('sha256', $payload),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedDemoOrder(
        int $demoOrderId,
        int $sourceOrderId = self::SOURCE_ORDER,
        ?int $attemptId = null,
        string $total = '4.28000',
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $payload = (string) json_encode($this->orderDocument($demoOrderId, $total));

        $this->connection->createCommand()->insert('{{%order_testing_demo_orders}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'store_source_id' => self::STORE_ID,
            'source_order_id' => $sourceOrderId,
            'demo_order_id' => $demoOrderId,
            'matched_attempt_id' => $attemptId,
            'customer_phone' => self::PHONE,
            'customer_first_name' => 'testa',
            'order_type' => 'delivery',
            'payment_method' => 'cash',
            'status' => 'completed',
            'total_amount' => $total,
            'address' => '162 N Main St',
            'city' => 'Freeport',
            'source_filename' => $sourceOrderId . '-' . $demoOrderId . '.json',
            'content_hash' => hash('sha256', $payload),
            'raw_payload' => $payload,
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedAttempt(): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order_testing_attempts}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'store_source_id' => self::STORE_ID,
            'source_order_id' => self::SOURCE_ORDER,
            'initiated_by_type' => 'ADMIN',
            'initiated_by_id' => $this->adminId(),
            'status' => 'MATCHED',
            'started_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertId();
    }

    /** A conversation with an order number, so the store page renders a row for it. */
    private function seedCall(int $orderId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $conversationId = bin2hex(random_bytes(16));

        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => $conversationId,
            'store_source_id' => self::STORE_ID,
            'mode' => 'COMMON',
            'recording_type' => 'MIXED',
            'order_id' => (string) $orderId,
            'generate_ai_audio' => 0,
            'uploaded_by_admin_id' => $this->adminId(),
            'created_at' => $now,
        ])->execute();

        $rowId = (int) (new Query($this->connection))
            ->select('id')->from('{{%audio_conversations}}')
            ->where(['public_id' => $conversationId])->scalar();

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'conversation_id' => $rowId,
            'uploaded_by_admin_id' => $this->adminId(),
            'original_filename' => 'order-testing.wav',
            'source_role' => 'COMMON',
            'status' => 'NOT_REQUESTED',
            'processing_stage' => 'QUEUED',
            'transcription_provider' => 'WHISPER',
            'duration_seconds' => 12.5,
            'review_count' => 0,
            'created_at' => $now,
        ])->execute();
    }

    /** @return array<string, mixed> */
    private function orderDocument(int $id, string $total): array
    {
        return [
            'id' => $id,
            'account_id' => self::STORE_ID,
            'is_test' => '1',
            'status' => 'completed',
            'type' => 'delivery',
            'payment_method' => 'cash',
            'total_amount' => $total,
            'data' => (string) json_encode([
                'reservation' => ['first_name' => 'testa', 'phone' => self::PHONE],
                'shipping' => ['city' => 'Freeport', 'state' => 'NY', 'zipcode' => '11520'],
            ]),
            'items' => [[
                'name' => 'Wonton Soup', 'quantity' => 2, 'price' => '2.00000', 'total' => '4.00000',
            ]],
        ];
    }

    /** Scoped to this suite's own fixtures, never a blanket delete. */
    private function cleanup(): void
    {
        $stores = [self::STORE_ID, self::OTHER_STORE_ID];
        $adminIds = (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::ADMIN])->column();

        $this->connection->createCommand()
            ->delete('{{%order_testing_demo_orders}}', ['store_source_id' => $stores])->execute();
        $this->connection->createCommand()
            ->delete('{{%order_testing_attempts}}', ['store_source_id' => $stores])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => $stores])->execute();

        if ($adminIds !== []) {
            $this->connection->createCommand()
                ->delete('{{%audio_transcription_jobs}}', ['uploaded_by_admin_id' => $adminIds])->execute();
            $this->connection->createCommand()
                ->delete('{{%audio_conversations}}', ['uploaded_by_admin_id' => $adminIds])->execute();
            $this->connection->createCommand()
                ->delete('{{%admin_users}}', ['id' => $adminIds])->execute();
        }

        $this->connection->createCommand()
            ->delete('{{%knowledge_bases}}', ['source_store_id' => $stores])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_stores}}', ['source_id' => $stores])->execute();
    }
}
