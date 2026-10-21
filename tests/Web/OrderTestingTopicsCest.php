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

use function dechex;
use function gmdate;
use function preg_replace;
use function str_pad;
use function str_repeat;

use const STR_PAD_LEFT;

/**
 * Assigning topics on Order Testing, which is where training content is managed.
 *
 * The controls moved here from Practice Orders, which is now a read-only training surface. What is
 * worth proving is both halves of that move: these controls work *here*, and the addresses behind them
 * are no longer reachable from there — a button taken off a page whose endpoint still answers is a
 * permission still granted, just harder to find.
 *
 * Fixture store ids sit in the `9876559xx` range, far outside the mirrored range, and cleanup is scoped
 * to this suite's own rows.
 */
final class OrderTestingTopicsCest
{
    private const USERNAME = '__kf_ot_topics_admin__';
    private const PASSWORD = 'PracticeTestPassw0rd!secure';
    private const STORE_ID = 987655860;
    private const STORE_URL = '/order-testing/store/' . self::STORE_ID;
    private const STORE_NAME = 'Order Testing Topics Fixture Store';
    private const ORDER = 16800401;
    /** A second order, so a filter can be proved to narrow rather than merely to render. */
    private const ORDER_B = 16800402;
    /**
     * A recording whose order id matches no row in `order58_orders` — the `#111` case from the live
     * store. It is a real shape in this database, not a contrivance: the order id is free text typed
     * at upload, so it can name an order that was never mirrored.
     */
    private const ORPHAN_ORDER = 16800999;
    /** Shaped like the provider's real ones, which are `31#1` and not a bare count. */
    private const SEQ = '31#1';
    /** A real mirrored order belonging to a DIFFERENT store, for the cross-store gate. */
    private const FOREIGN_ORDER = 16800444;
    private const TOPIC = 'ZZ OT Topic';
    private const TOPIC_B = 'ZZ OT Topic Second';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->seedStore();
        $this->seedOrder(self::ORDER, self::SEQ);
        // Deliberately without one: the column is nullable, and a mirrored order with no seq must
        // still be offered a receipt, because the order itself is real.
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

    /** The management table: a checkbox per eligible row, and a topic control in the last column. */
    public function theTableCarriesTheManagementColumns(WebTester $I): void
    {
        $this->seedTopic();

        $I->amOnPage(self::STORE_URL);
        $I->seeResponseCodeIs(200);

        $I->see('Set Topic', 'thead');
        $I->seeElement('thead [data-practice-select-all]');
        $I->seeElement('tbody [data-practice-select][value="' . self::ORDER . '"]');
        $I->seeElement('[data-practice-assign-select]');
        $I->seeElement('#practice-bulk-form');
    }

    // ------------------------------------------------------------------ Seq and View Receipt

    /**
     * The two new columns exist, and the colgroup still describes the header row.
     *
     * The count check is the one that matters. A colgroup one `col` short does not leave the last
     * column unsized — it shifts every width one place left, which is how a 21rem width once landed
     * on Agent Events and collapsed Set Topic to nothing. Counted against each other rather than
     * against a number, so adding an eleventh column does not require editing this test.
     */
    public function theTableCarriesSeqAndViewReceipt(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);
        $I->seeResponseCodeIs(200);

        $I->see('Seq', 'thead');
        $I->see('View Receipt', 'thead');

        $source = $I->grabPageSource();
        // `'<th'` alone would also count `<thead`, hence the two forms.
        $headers = substr_count($source, '<th ') + substr_count($source, '<th>');

        Assert::assertSame(
            $headers,
            substr_count($source, '<col class="'),
            'The colgroup and the header row must describe the same columns.',
        );
    }

    /** The provider's own number, printed exactly as mirrored rather than reformatted. */
    public function theSeqCellShowsTheProvidersOwnNumber(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);

        $I->see(self::SEQ, '.ot-seq');
    }

    /**
     * The link is a real URL on this application, opened in a new tab.
     *
     * `rel="noopener noreferrer"` is the part worth pinning: without it the opened tab keeps a handle
     * on this page through `window.opener`, and what it opens is a document from somebody else's
     * server.
     */
    public function theReceiptLinkPointsAtThisApplicationAndOpensANewTab(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);

        $expected = self::STORE_URL . '/order/' . self::ORDER . '/receipt';

        $I->seeElement('a.ot-receipt[href="' . $expected . '"][target="_blank"][rel="noopener noreferrer"]');
    }

    /** No token, no provider host, and no upstream call while the table loads. */
    public function theTableNeverExposesTheProvidersHostOrCredential(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);

        $source = $I->grabPageSource();

        Assert::assertStringNotContainsString('order58.com', $source);
        Assert::assertStringNotContainsString('Bearer', $source);
        Assert::assertStringNotContainsString('account_id', $source);
    }

    /**
     * A mirrored order with no seq still gets a receipt link.
     *
     * "No seq" and "no order" are different facts, and only the second means there is no verified id
     * to ask the provider about.
     */
    public function anOrderWithNoSeqIsStillOfferedAReceipt(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);

        $I->seeElement('a.ot-receipt[href="' . self::STORE_URL . '/order/' . self::ORDER_B . '/receipt"]');
    }

    /** An order id that names no mirrored row offers no link, because there is nothing to ask about. */
    public function anUnmirroredOrderIsOfferedNoReceipt(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL);

        $I->dontSeeElement(
            'a.ot-receipt[href="' . self::STORE_URL . '/order/' . self::ORPHAN_ORDER . '/receipt"]',
        );
    }

    /**
     * The gate that a hand-edited URL meets.
     *
     * `order58_orders` is unique on `(account_id, source_order_id)` and not on the order id alone, so
     * an order that exists under another store must not be fetchable from this one's page. The
     * lookup is by the pair, so this is a 404 — the same answer as an order that does not exist,
     * which is also the right answer to give.
     */
    public function anotherStoresOrderCannotBeFetchedFromThisStoresUrl(WebTester $I): void
    {
        $I->amOnPage(self::STORE_URL . '/order/' . self::ORPHAN_ORDER . '/receipt');

        $I->seeResponseCodeIs(404);
        $I->see('not on this store');
        // The provider was never called, so nothing of theirs is on the page.
        $I->dontSee('Bearer');
    }

    /**
     * A real order, mirrored under a DIFFERENT store, requested from this store's URL.
     *
     * This is the gate that actually matters, and it is load-bearing rather than belt-and-braces:
     * the provider was measured on 2026-10-21 answering a receipt request for account 1301 carrying
     * an order id belonging to 1731. **Upstream does not check that the order belongs to the account**,
     * so the only thing standing between a hand-edited URL and another merchant's receipt is this
     * lookup by `(account_id, source_order_id)`.
     *
     * Nothing leaves the building during this test: the 404 is decided before any client is called,
     * which is also why it is safe to assert here rather than against a mock.
     */
    public function arealOrderBelongingToAnotherStoreIs404FromThisStore(WebTester $I): void
    {
        $other = self::STORE_ID + 1;
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $other,
            'source_order_id' => self::FOREIGN_ORDER,
            'seq' => '99#9',
            'payload_json' => '{}',
            'content_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $I->amOnPage(self::STORE_URL . '/order/' . self::FOREIGN_ORDER . '/receipt');

        $I->seeResponseCodeIs(404);
        $I->see('not on this store');
    }

    /**
     * The dropdown opens on what is stored, not on the first topic in the list.
     *
     * Initialising it anywhere else is how a control silently proposes a change nobody asked for.
     */
    /**
     * The inline Save ships inside `<noscript>`, which is what stops it being seen at all.
     *
     * It used to be rendered plainly and hidden by the script on DOMContentLoaded. That is correct in
     * the end state and wrong on the way to it: the button is painted as soon as the row is parsed and
     * stays on screen until the script runs, which on this page is after a great deal of other
     * behaviour has loaded. The fix is in the markup, so the guard has to be on the markup — asserted
     * on the raw response, because whether a parser builds `<noscript>` children depends on whether it
     * thinks scripting is on, and the property here is about the bytes, not the tree.
     */
    public function theInlineSaveIsOnlyEverRenderedInsideNoscript(WebTester $I): void
    {
        $this->seedTopic();

        $I->amOnPage(self::STORE_URL);
        $I->seeResponseCodeIs(200);

        $source = $I->grabPageSource();

        Assert::assertStringContainsString(
            'data-practice-assign-save',
            $source,
            'the no-script fallback disappeared entirely; a reader without JavaScript cannot save',
        );

        $outsideNoscript = (string) preg_replace('#<noscript\b[^>]*>.*?</noscript>#s', '', $source);

        Assert::assertStringNotContainsString(
            'data-practice-assign-save',
            $outsideNoscript,
            'a Save button is rendered outside <noscript>, so it paints before the script hides it',
        );
    }

    /**
     * The control shows what is STORED, so a column of them can be read at a glance.
     *
     * Rendered by the server from the saved assignment rather than driven by the selected option, so
     * choosing a different topic cannot make a row look saved before it is. Cancel therefore has
     * nothing to restore, and a refused save leaves the row describing itself correctly.
     */
    public function anAssignedDropdownIsMarkedApartFromAnEmptyOne(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId, self::ORDER);

        $I->amOnPage(self::STORE_URL);

        $I->seeElement(
            '#practice-topic-' . self::ORDER . '.practice-assign__select--assigned',
        );
        // The order with nothing on it keeps the neutral control.
        $I->dontSeeElement(
            '#practice-topic-' . self::ORDER_B . '.practice-assign__select--assigned',
        );
        $I->dontSeeElement(
            '#practice-topic-' . self::ORDER_B . '.practice-assign__select--historical',
        );
    }

    /** A topic that is no longer offered is marked apart from a live one, and stays on its order. */
    public function aHistoricalAssignmentIsMarkedApartFromALiveOne(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId, self::ORDER);
        $this->archiveTopic($topicId);

        $I->amOnPage(self::STORE_URL);

        $I->seeElement(
            '#practice-topic-' . self::ORDER . '.practice-assign__select--historical',
        );
        $I->dontSeeElement(
            '#practice-topic-' . self::ORDER . '.practice-assign__select--assigned',
        );
    }

    public function theDropdownOpensOnTheAssignedTopic(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage(self::STORE_URL);

        $I->seeOptionIsSelected('#practice-topic-' . self::ORDER, self::TOPIC);
        $I->seeElement('[data-practice-assign-select][data-practice-saved="' . $topicId . '"]');
        // Unassigning is offered, because the business rule allows it.
        $I->see('Unassign', '#practice-topic-' . self::ORDER);
    }

    public function anUnassignedRowOpensOnThePlaceholder(WebTester $I): void
    {
        $this->seedTopic();

        $I->amOnPage(self::STORE_URL);

        $I->seeOptionIsSelected('#practice-topic-' . self::ORDER, 'Select Topic');
        $I->seeElement('[data-practice-assign-select][data-practice-saved=""]');
    }

    /** Submitting the row's own form assigns it — the path the confirmation takes on Confirm. */
    public function assigningATopicSavesIt(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage(self::STORE_URL);
        $I->submitForm('[data-practice-order="' . self::ORDER . '"]', ['topic_id' => (string) $topicId]);

        $I->see('assigned', '.alert');
        Assert::assertSame($topicId, $this->assignedTopicId(self::ORDER));

        // And the reload shows what was stored.
        $I->amOnPage(self::STORE_URL);
        $I->seeOptionIsSelected('#practice-topic-' . self::ORDER, self::TOPIC);
    }

    public function choosingTheEmptyOptionUnassigns(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage(self::STORE_URL);
        $I->submitForm('[data-practice-order="' . self::ORDER . '"]', ['topic_id' => '']);

        $I->see('removed', '.alert');
        Assert::assertNull($this->assignedTopicId(self::ORDER));
    }

    /**
     * **A historical topic is offered back to itself, and nothing else offers it.**
     *
     * Without its own option the control would initialise on "Select Topic", and the first save would
     * quietly unassign a topic the order has carried for months.
     */
    public function aDeletedAssignedTopicStaysSelectableOnItsOwnRow(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);
        $this->setTopicStatus($topicId, 'ARCHIVED');

        $I->amOnPage(self::STORE_URL);

        $I->seeElement('#practice-topic-' . self::ORDER . ' option[value="' . $topicId . '"][selected]');
        $I->see('Deleted', '#practice-topic-' . self::ORDER);
        // Not offered on a row that does not already carry it.
        $I->dontSeeElement('#practice-topic-' . self::ORDER_B . ' option[value="' . $topicId . '"]');
        $I->dontSeeElement('#practice-bulk-topic option[value="' . $topicId . '"]');
    }

    public function anArchivedTopicCannotBeNewlyAssigned(WebTester $I): void
    {
        $topicId = $this->seedTopic('ARCHIVED');

        $I->amOnPage(self::STORE_URL);
        $I->submitForm('[data-practice-order="' . self::ORDER . '"]', ['topic_id' => (string) $topicId]);

        $I->see('cannot be assigned', '.alert');
        Assert::assertNull($this->assignedTopicId(self::ORDER));
    }

    /** A recording whose order was never mirrored offers neither a checkbox nor a control. */
    public function aRowWithNoSourceOrderOffersNothingToAssign(WebTester $I): void
    {
        $this->seedTopic();

        $I->amOnPage(self::STORE_URL);

        $I->see('No matching source order', '.practice-topic-cell');
        $I->dontSeeElement('tbody [data-practice-select][value="' . self::ORPHAN_ORDER . '"]');
        $I->dontSeeElement('#practice-topic-' . self::ORPHAN_ORDER);
    }

    /** And the server refuses it too, so the absent control is the second lock, not the only one. */
    public function assigningToAnOrderThatDoesNotExistIsRefused(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage(self::STORE_URL);
        $I->submitForm('[data-practice-order="' . self::ORDER . '"]', [
            'source_order_id' => (string) self::ORPHAN_ORDER,
            'topic_id' => (string) $topicId,
        ]);

        $I->see('belong to this store', '.alert');
        Assert::assertNull($this->assignedTopicId(self::ORPHAN_ORDER));
    }

    public function anOrderFromAnotherStoreIsRefused(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage(self::STORE_URL);
        $I->submitForm('[data-practice-order="' . self::ORDER . '"]', [
            'source_order_id' => '99999999',
            'topic_id' => (string) $topicId,
        ]);

        $I->see('belong to this store', '.alert');
        Assert::assertNull($this->assignedTopicId(99999999));
    }

    // ---------------------------------------------------------------- bulk

    public function theBulkToolbarAssignsEverySelectedOrder(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage(self::STORE_URL);
        $I->submitForm('#practice-bulk-form', [
            'topic_id' => (string) $topicId,
            'source_order_ids' => [(string) self::ORDER, (string) self::ORDER_B],
        ]);

        $I->see('assigned to 2 orders', '.alert');
        Assert::assertSame($topicId, $this->assignedTopicId(self::ORDER));
        Assert::assertSame($topicId, $this->assignedTopicId(self::ORDER_B));
    }

    /** All or nothing: one invalid id in the batch and not a single row is written. */
    public function aBulkRequestNamingAnInvalidOrderWritesNothing(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage(self::STORE_URL);
        $I->submitForm('#practice-bulk-form', [
            'topic_id' => (string) $topicId,
            'source_order_ids' => [(string) self::ORDER, (string) self::ORPHAN_ORDER],
        ]);

        $I->see('belong to this store', '.alert');
        Assert::assertNull($this->assignedTopicId(self::ORDER), 'the valid half must not be written either');
    }

    /**
     * The bulk toolbar opens on "Not Selected", and that is the absence of a choice.
     *
     * The page disables the button while it shows, but a hand-crafted POST does not run the page's
     * script. The endpoint has to refuse it too — and in particular must not read it the way a single
     * row's dropdown reads an empty choice, which is "unassign". The row assigned beforehand is the
     * assertion: it still has its topic afterwards.
     */
    public function aBulkRequestWithNoTopicChosenChangesNothing(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage(self::STORE_URL);
        $I->submitForm('#practice-bulk-form', [
            'topic_id' => (string) $topicId,
            'source_order_ids' => [(string) self::ORDER],
        ]);
        Assert::assertSame($topicId, $this->assignedTopicId(self::ORDER));

        $I->amOnPage(self::STORE_URL);
        $I->submitForm('#practice-bulk-form', [
            'topic_id' => '',
            'source_order_ids' => [(string) self::ORDER, (string) self::ORDER_B],
        ]);

        $I->see('Choose a topic', '.alert');
        Assert::assertSame($topicId, $this->assignedTopicId(self::ORDER), 'the topic was taken off');
        Assert::assertNull($this->assignedTopicId(self::ORDER_B), 'something was written anyway');
    }

    // ---------------------------------------------------------------- the filter here too

    public function theManagementTableFiltersBySeveralTopics(WebTester $I): void
    {
        $first = $this->seedTopic();
        $second = $this->seedTopic('ACTIVE', self::TOPIC_B);
        $this->assignDirectly($first);
        $this->assignDirectly($second, self::ORDER_B);

        $I->amOnPage(self::STORE_URL . '?topics[]=' . $first);
        $I->see((string) self::ORDER, 'tbody');
        $I->dontSee((string) self::ORDER_B, 'tbody');

        $I->amOnPage(self::STORE_URL . '?topics[]=' . $first . '&topics[]=' . $second);
        $I->see((string) self::ORDER, 'tbody');
        $I->see((string) self::ORDER_B, 'tbody');
    }

    /** The chosen filter rides the assignment redirect, so a worked-through list is not lost. */
    public function theFilterSurvivesAnAssignment(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage(self::STORE_URL . '?topics[]=none');
        $I->submitForm('[data-practice-order="' . self::ORDER . '"]', ['topic_id' => (string) $topicId]);

        $I->seeInCurrentUrl('topics');
        $I->seeInCurrentUrl('none');
        $I->see('assigned', '.alert');
    }

    public function theFilterSurvivesABulkAssignment(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage(self::STORE_URL . '?topics[]=none');
        $I->submitForm('#practice-bulk-form', [
            'topic_id' => (string) $topicId,
            'source_order_ids' => [(string) self::ORDER],
        ]);

        $I->seeInCurrentUrl('topics');
        $I->see('assigned to 1 order', '.alert');
    }

    /** The confirmation dialogs the script fills, present and carrying no form of their own. */
    public function theConfirmationDialogsArePresentAndInert(WebTester $I): void
    {
        $this->seedTopic();

        $I->amOnPage(self::STORE_URL);

        $I->seeElement('#practice-confirm-dialog');
        $I->seeElement('#practice-bulk-confirm-dialog');
        $I->dontSeeElement('#practice-confirm-dialog form');
        $I->dontSeeElement('#practice-bulk-confirm-dialog form');
        $I->see('Confirm assignment', '#practice-confirm-dialog');
        $I->see('Cancel', '#practice-confirm-dialog');
    }

    /** The old addresses are gone: a control moved without its endpoint is a permission still granted. */
    public function thePracticeOrdersAssignmentAddressesAreGone(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage(self::STORE_URL);
        $I->dontSeeElement('form[action$="/practice-orders/store/' . self::STORE_ID . '/topic"]');

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);
        $I->dontSeeElement('form[action*="/topic"]');
        Assert::assertNull($this->assignedTopicId(self::ORDER));
        Assert::assertGreaterThan(0, $topicId);
    }

    // ---------------------------------------------------------------- fixtures

    private function seedTopic(string $status = 'ACTIVE', string $name = self::TOPIC): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%practice_topics}}', [
            'name' => $name,
            'name_key' => mb_strtolower($name),
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        return (int) $this->connection->getLastInsertID();
    }

    /** Soft-deletes a topic the way Topic Management does, leaving its assignments alone. */
    private function archiveTopic(int $topicId): void
    {
        $this->connection->createCommand()
            ->update('{{%practice_topics}}', ['status' => 'ARCHIVED'], ['id' => $topicId])
            ->execute();
    }

    private function assignDirectly(int $topicId, int $orderId = self::ORDER): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%practice_order_topics}}', [
            'store_source_id' => self::STORE_ID,
            'source_order_id' => $orderId,
            'topic_id' => $topicId,
            'assigned_by_type' => 'ADMIN',
            'assigned_by_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /**
     * Enough orders to need a second page, each with its own recording.
     *
     * Written straight to the tables rather than through the upload path: this is about how many rows
     * the page is handed, not about how they got there. `cleanup()` removes them with the rest, since
     * it works by store.
     */
    private function seedManyOrders(int $count): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $adminId = (int) (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::USERNAME])->scalar();

        for ($i = 0; $i < $count; $i++) {
            $orderId = 16801000 + $i;
            // Hex, because the job routes constrain `{publicId}` to 32 hex characters and the page
            // builds a URL for every recording that has audio. A fixture id with a letter outside
            // a-f makes the URL generator throw, which reads as a broken page rather than a bad id.
            $publicId = 'ab' . str_pad(dechex($i), 30, '0', STR_PAD_LEFT);

            $this->connection->createCommand()->insert('{{%order58_orders}}', [
                'account_id' => self::STORE_ID,
                'source_order_id' => $orderId,
                'payload_json' => '{}',
                'content_hash' => str_repeat('0', 64),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->execute();

            $this->connection->createCommand()->insert('{{%audio_conversations}}', [
                'public_id' => $publicId,
                'store_source_id' => self::STORE_ID,
                'mode' => 'COMMON',
                'recording_type' => 'MIXED',
                'order_id' => (string) $orderId,
                'generate_ai_audio' => 0,
                'uploaded_by_admin_id' => $adminId,
                'created_at' => $now,
            ])->execute();

            $conversationId = (int) (new Query($this->connection))
                ->select('id')->from('{{%audio_conversations}}')
                ->where(['public_id' => $publicId])->scalar();

            $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
                'public_id' => 'cd' . str_pad(dechex($i), 30, '0', STR_PAD_LEFT),
                'conversation_id' => $conversationId,
                'uploaded_by_admin_id' => $adminId,
                'original_filename' => 'page.wav',
                'retained_audio_path' => 'source.wav',
                'source_role' => 'COMMON',
                'status' => 'COMPLETED',
                'processing_stage' => 'COMPLETED',
                'transcription_provider' => 'WHISPER',
                'duration_seconds' => 1.0,
                'review_count' => 0,
                'created_at' => $now,
            ])->execute();
        }
    }

    private function describeTopic(int $topicId, string $description): void
    {
        $this->connection->createCommand()
            ->update('{{%practice_topics}}', ['description' => $description], ['id' => $topicId])->execute();
    }

    private function setTopicStatus(int $topicId, string $status): void
    {
        $this->connection->createCommand()
            ->update('{{%practice_topics}}', ['status' => $status], ['id' => $topicId])->execute();
    }

    private function assignedTopicId(int $orderId): ?int
    {
        $value = (new Query($this->connection))
            ->select('topic_id')->from('{{%practice_order_topics}}')
            ->where(['store_source_id' => self::STORE_ID, 'source_order_id' => $orderId])
            ->scalar();

        return $value === false || $value === null ? null : (int) $value;
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

        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => self::STORE_NAME,
            'slug' => 'kf-practice-' . self::STORE_ID,
            'source_system' => 'order58',
            'source_store_id' => self::STORE_ID,
            'source_name' => self::STORE_NAME,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function seedOrder(int $orderId, ?string $seq = null): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => self::STORE_ID,
            'source_order_id' => $orderId,
            // The provider's own printed number. Shaped like the real ones — `31#1`, not `31` — so a
            // test cannot pass against a column that silently holds a count.
            'seq' => $seq,
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
            'original_filename' => 'practice.wav',
            // Held audio, because the practice surfaces list only recordings that can be practised —
            // a conversation row with nothing behind it is not one.
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

    /** Scoped to this suite's own store, order, topic and administrator. Never a blanket delete. */
    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%practice_order_topics}}', ['store_source_id' => self::STORE_ID])->execute();
        // Both names explicitly, as an IN clause: a LIKE here would be one typo away from deleting
        // somebody else's topics.
        $this->connection->createCommand()
            ->delete('{{%practice_topics}}', ['name' => [self::TOPIC, self::TOPIC_B]])->execute();

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
            ->delete('{{%order58_orders}}', ['account_id' => [self::STORE_ID, self::STORE_ID + 1]])->execute();
        $this->connection->createCommand()
            ->delete('{{%knowledge_bases}}', ['source_store_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_stores}}', ['source_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%admin_users}}', ['username' => self::USERNAME])->execute();
    }
}
