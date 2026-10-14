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
use function str_pad;
use function str_repeat;

use const STR_PAD_LEFT;

/**
 * Practice Orders for an administrator: the picker, the store page's two new columns, and assignment.
 *
 * What is worth proving here is that this surface really is the Order Testing page with a topic on it
 * — the same table, the same conversation cell — rather than a copy that will drift. So the assertions
 * are about the columns that are new and the links that differ, and the Order Testing suite next door
 * proves the shared half still renders as it did.
 *
 * Fixture store ids sit in the `9876559xx` range, far outside the mirrored range, and cleanup is scoped
 * to this suite's own rows.
 */
final class PracticeOrdersCest
{
    private const USERNAME = '__kf_practice_admin__';
    private const PASSWORD = 'PracticeTestPassw0rd!secure';
    private const STORE_ID = 987655820;
    private const STORE_NAME = 'Practice Orders Fixture Store';
    private const ORDER = 16800401;
    /** A second order, so a filter can be proved to narrow rather than merely to render. */
    private const ORDER_B = 16800402;
    /**
     * A recording whose order id matches no row in `order58_orders` — the `#111` case from the live
     * store. It is a real shape in this database, not a contrivance: the order id is free text typed
     * at upload, so it can name an order that was never mirrored.
     */
    private const ORPHAN_ORDER = 16800999;
    private const TOPIC = 'ZZ Practice Topic';
    private const TOPIC_B = 'ZZ Practice Topic Empty';

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

    public function thePickerListsStoresAndLinksIntoPracticeOrders(WebTester $I): void
    {
        $I->amOnPage('/practice-orders?q=' . urlencode(self::STORE_NAME));

        $I->seeResponseCodeIs(200);
        $I->see('Practice Orders', '.page-header__title');
        $I->see(self::STORE_NAME, '.store-grid');
        $I->see('View Practice Orders', '.store-grid');
        $I->seeElement('.store-grid a[href="/practice-orders/store/' . self::STORE_ID . '"]');
    }

    /** The picker shares its body with Order Testing, so the filters must be the same ones. */
    public function thePickerCarriesTheSameFiltersAsOrderTesting(WebTester $I): void
    {
        $I->amOnPage('/practice-orders');

        $I->seeElement('input[name="q"]');
        $I->seeElement('.filter-bar .filter-chip');
        $I->seeElement('.alpha-nav');
    }

    public function theStorePageIsTheOrderTestingTablePlusTwoColumns(WebTester $I): void
    {
        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->seeResponseCodeIs(200);

        // The original five, unchanged.
        $I->see('Order No#', 'thead');
        $I->see('Recording / Conversation', 'thead');
        $I->see('Status', 'thead');
        $I->see('Demo URL', 'thead');
        $I->see('Demo Orders', 'thead');

        // ...and the two that are new.
        $I->seeElement('thead [data-practice-select-all]');
        $I->see('Set Topic', 'thead');

        $I->seeElement('tbody [data-practice-select][value="' . self::ORDER . '"]');
        $I->see('Not Assigned', '.practice-topic-cell');
    }

    /** Its links must come back to Practice Orders, not wander into Order Testing. */
    public function theStorePageLinksBackToItsOwnSurface(WebTester $I): void
    {
        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        // Scoped to the page's own header: the sidebar links to Order Testing on every page, so an
        // unscoped assertion would be testing the menu rather than this surface.
        $I->seeElement('.page-header a[href="/practice-orders"]');
        $I->dontSeeElement('.page-header a[href="/order-testing"]');
    }

    public function anAdminAssignsATopicToOneOrder(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);
        $I->submitForm('[data-practice-assign-form]', [
            'source_order_id' => (string) self::ORDER,
            'topic_id' => (string) $topicId,
        ]);

        $I->seeCurrentUrlEquals('/practice-orders/store/' . self::STORE_ID);
        $I->see('assigned', '.alert');
        $I->see(self::TOPIC, '.practice-topic-cell');
        Assert::assertSame($topicId, $this->assignedTopicId(self::ORDER));
    }

    public function removingATopicLeavesTheOrderUnassigned(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);
        $I->see(self::TOPIC, '.practice-topic-cell');

        $I->submitForm('[data-practice-assign-form]', [
            'source_order_id' => (string) self::ORDER,
            'topic_id' => '',
        ]);

        $I->see('removed', '.alert');
        $I->see('Not Assigned', '.practice-topic-cell');
    }

    public function theBulkToolbarAssignsEveryOrderThatWasSelected(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);
        $I->seeElement('#practice-bulk-form');

        $I->submitForm('#practice-bulk-form', [
            'topic_id' => (string) $topicId,
            'source_order_ids' => [(string) self::ORDER],
        ]);

        $I->see('assigned to 1 order', '.alert');
        Assert::assertSame($topicId, $this->assignedTopicId(self::ORDER));
    }

    /**
     * The security case: an order id from another store, posted at this store's endpoint.
     *
     * It must be refused and nothing written — the store id comes from the path, and the body is not
     * trusted to name an order of it.
     */
    public function anOrderFromAnotherStoreIsRefused(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);
        $I->submitForm('[data-practice-assign-form]', [
            'source_order_id' => '99999999',
            'topic_id' => (string) $topicId,
        ]);

        $I->see('belong to this store', '.alert');
        Assert::assertNull($this->assignedTopicId(99999999));
    }

    public function anArchivedTopicCannotBeAssigned(WebTester $I): void
    {
        $topicId = $this->seedTopic('ARCHIVED');

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);
        $I->submitForm('[data-practice-assign-form]', [
            'source_order_id' => (string) self::ORDER,
            'topic_id' => (string) $topicId,
        ]);

        $I->see('cannot be assigned', '.alert');
        Assert::assertNull($this->assignedTopicId(self::ORDER));
    }

    // ---------------------------------------------------------------- the topic filter

    public function theTopicFilterNarrowsTheListToThatTopic(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID . '?topic=' . $topicId);

        $I->seeResponseCodeIs(200);
        $I->seeElement('tbody [data-practice-select][value="' . self::ORDER . '"]');
        $I->dontSeeElement('tbody [data-practice-select][value="' . self::ORDER_B . '"]');
        // The control agrees with the rows rather than resetting itself to "All topics".
        $I->seeOptionIsSelected('select[name="topic"]', self::TOPIC);
    }

    public function theNotAssignedFilterShowsOnlyOrdersWithNoTopic(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID . '?topic=none');

        $I->dontSeeElement('tbody [data-practice-select][value="' . self::ORDER . '"]');
        $I->seeElement('tbody [data-practice-select][value="' . self::ORDER_B . '"]');
        $I->seeOptionIsSelected('select[name="topic"]', 'Not assigned');
    }

    /** Unfiltered is still every order, which is what the page does without the parameter. */
    public function noFilterShowsEveryOrder(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->seeElement('tbody [data-practice-select][value="' . self::ORDER . '"]');
        $I->seeElement('tbody [data-practice-select][value="' . self::ORDER_B . '"]');
        $I->seeOptionIsSelected('select[name="topic"]', 'All topics');
    }

    /** A hand-edited or stale value filters nothing rather than erroring. */
    public function anUnrecognisedFilterValueShowsEveryOrder(WebTester $I): void
    {
        $I->amOnPage('/practice-orders/store/' . self::STORE_ID . '?topic=not-a-topic');

        $I->seeResponseCodeIs(200);
        $I->seeElement('tbody [data-practice-select][value="' . self::ORDER . '"]');
        $I->seeElement('tbody [data-practice-select][value="' . self::ORDER_B . '"]');
        $I->seeOptionIsSelected('select[name="topic"]', 'All topics');
    }

    /**
     * Setting a topic returns to the filtered list, not to every order.
     *
     * Working through "Not assigned" one order at a time is the whole point of the filter, and a
     * redirect that dropped it would make the list restart after every assignment.
     */
    public function theFilterSurvivesAnAssignment(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID . '?topic=none');
        $I->submitForm('[data-practice-assign-form]', [
            'source_order_id' => (string) self::ORDER,
            'topic_id' => (string) $topicId,
        ]);

        $I->seeCurrentUrlEquals('/practice-orders/store/' . self::STORE_ID . '?topic=none');
        $I->see('assigned', '.alert');
        // And the order that just got one has left the list it was filtered into.
        $I->dontSeeElement('tbody [data-practice-select][value="' . self::ORDER . '"]');
        $I->seeElement('tbody [data-practice-select][value="' . self::ORDER_B . '"]');
    }

    public function theFilterSurvivesABulkAssignment(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID . '?topic=none');
        $I->submitForm('#practice-bulk-form', [
            'topic_id' => (string) $topicId,
            'source_order_ids' => [(string) self::ORDER],
        ]);

        $I->seeCurrentUrlEquals('/practice-orders/store/' . self::STORE_ID . '?topic=none');
        $I->see('assigned to 1 order', '.alert');
    }

    /** An empty filtered list says the filter emptied it, not that the store has no recordings. */
    public function aFilterThatMatchesNothingSaysSoAndOffersTheWayBack(WebTester $I): void
    {
        $empty = $this->seedTopic('ACTIVE', self::TOPIC_B);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID . '?topic=' . $empty);

        $I->seeResponseCodeIs(200);
        $I->see('No orders match this topic filter', '.a2t-empty-state');
        $I->dontSee('No audio recordings yet', '.a2t-empty-state');
        $I->seeElement('.a2t-empty-state a[href="/practice-orders/store/' . self::STORE_ID . '"]');
    }

    // ---------------------------------------------------------------- the topic details modal

    public function theAssignedTopicNameOpensItsDetails(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->describeTopic($topicId, 'What this topic is for.');
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        // The name is its own control, carrying everything the dialog shows — so opening it needs no
        // request, and there is no endpoint to authorise separately.
        $I->seeElement('.practice-topic-cell [data-practice-topic-details]');
        $I->seeElement('[data-practice-topic-details][data-practice-topic-name="' . self::TOPIC . '"]');
        $I->seeElement('[data-practice-topic-details][data-practice-topic-description="What this topic is for."]');
        $I->seeElement('[data-practice-topic-details][data-practice-topic-status="Active"]');

        // And the dialog it fills is present, read-only, with no form in it.
        $I->seeElement('#practice-topic-details-dialog');
        $I->dontSeeElement('#practice-topic-details-dialog form');
        $I->see('Topic Details', '#practice-topic-details-dialog .source-modal__title');
    }

    /** An empty description is carried as empty; the dialog is what says "none provided". */
    public function aTopicWithNoDescriptionCarriesAnEmptyOne(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->seeElement('[data-practice-topic-details][data-practice-topic-description=""]');
    }

    /** An active topic must never be labelled as no longer offered. */
    public function anActiveTopicIsNotMarkedUnavailable(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->dontSeeElement('.practice-topic-cell .badge--muted');
        $I->seeElement('[data-practice-topic-details][data-practice-topic-note=""]');
    }

    /** A deleted topic stays on the order that carries it, and says which of the two it is. */
    public function aDeletedTopicStillReadsOnTheOrderThatCarriesIt(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);
        $this->setTopicStatus($topicId, 'ARCHIVED');

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->see(self::TOPIC, '.practice-topic-cell');
        // A badge in the column; the sentence that explains it lives in the details dialog.
        $I->see('Deleted', '.practice-topic-cell .badge--muted');
        $I->seeElement('[data-practice-topic-details][data-practice-topic-status="Deleted"]');
        // And it is no longer a choice anywhere.
        $I->dontSeeElement('#practice-bulk-topic option[value="' . $topicId . '"]');
    }

    public function anInactiveTopicSaysInactiveRatherThanJustUnavailable(WebTester $I): void
    {
        $topicId = $this->seedTopic('INACTIVE');
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->see('Inactive', '.practice-topic-cell .badge--muted');
        $I->seeElement('[data-practice-topic-details][data-practice-topic-status="Inactive"]');
    }

    // ---------------------------------------------------------------- rows with no source order

    /**
     * A recording whose order was never mirrored cannot be practised, and the page says so rather than
     * offering a control the server would refuse.
     */
    public function arecordingWithNoSourceOrderOffersNoAssignment(WebTester $I): void
    {
        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->see('No matching source order', '.practice-topic-cell');
        $I->dontSeeElement('tbody [data-practice-select][value="' . self::ORPHAN_ORDER . '"]');
        $I->dontSeeElement('[data-practice-set-topic="' . self::ORPHAN_ORDER . '"]');
        // The recording itself is still fully listed.
        $I->see((string) self::ORPHAN_ORDER, 'tbody');
    }

    /** And the server refuses it too, so hiding the control is the second line of defence, not the first. */
    public function assigningToAnOrderThatDoesNotExistIsRefused(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);
        $I->submitForm('[data-practice-assign-form]', [
            'source_order_id' => (string) self::ORPHAN_ORDER,
            'topic_id' => (string) $topicId,
        ]);

        $I->see('belong to this store', '.alert');
        Assert::assertNull($this->assignedTopicId(self::ORPHAN_ORDER));
    }

    /** A bulk request naming it is refused whole — "14 of 15 saved" is not an outcome anyone can act on. */
    public function aBulkAssignmentNamingAnInvalidOrderWritesNothing(WebTester $I): void
    {
        $topicId = $this->seedTopic();

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);
        $I->submitForm('#practice-bulk-form', [
            'topic_id' => (string) $topicId,
            'source_order_ids' => [(string) self::ORDER, (string) self::ORPHAN_ORDER],
        ]);

        $I->see('belong to this store', '.alert');
        Assert::assertNull($this->assignedTopicId(self::ORDER), 'the valid half must not be written either');
        Assert::assertNull($this->assignedTopicId(self::ORPHAN_ORDER));
    }

    // ---------------------------------------------------------------- the filter is explicit

    /**
     * The select carries no script hook, so choosing an option cannot navigate.
     *
     * PhpBrowser runs no JavaScript, so the absence of the hook is the thing to assert: it is what the
     * behaviour depends on, and it is what regressed last time.
     */
    public function theFilterSelectDoesNotSubmitOnItsOwn(WebTester $I): void
    {
        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->seeElement('.practice-filter select[name="topic"]');
        $I->dontSeeElement('.practice-filter select[data-practice-filter]');
        $I->dontSeeElement('.practice-filter select[onchange]');
        // Apply is a real submit button on a real GET form, which is what does the filtering.
        $I->seeElement('.practice-filter[method="get"] button[type="submit"]');
        $I->see('Apply', '.practice-filter');
    }

    /** Clear is a link straight back to the unfiltered list. */
    public function clearReturnsToEveryOrder(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID . '?topic=' . $topicId);
        $I->see('Clear', '.practice-filter');

        $I->click('Clear', '.practice-filter');

        $I->seeCurrentUrlEquals('/practice-orders/store/' . self::STORE_ID);
        $I->seeElement('tbody [data-practice-select][value="' . self::ORDER_B . '"]');
    }

    /** Set Topic and Change are real, enabled buttons — not the grey that reads as disabled. */
    public function theAssignmentButtonsLookEnabled(WebTester $I): void
    {
        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);

        $I->seeElement('.practice-topic-cell .btn--primary[data-practice-set-topic]');
        $I->dontSeeElement('.practice-topic-cell .btn[disabled][data-practice-set-topic]');

        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);
        $I->seeElement('.practice-topic-cell .btn--outline-primary[data-practice-set-topic]');
    }

    /**
     * **A store with more than one page of orders.**
     *
     * The pager partial is only rendered when there is a second page, so a wrong path to it is a 500
     * that no fixture with a handful of rows can see — which is exactly how one stood unnoticed. Both
     * surfaces render the same template, so both are checked.
     */
    public function aStoreWithASecondPageOfOrdersStillRenders(WebTester $I): void
    {
        $this->seedManyOrders(16);

        foreach (['/practice-orders/store/', '/order-testing/store/'] as $prefix) {
            $I->amOnPage($prefix . self::STORE_ID);
            $I->seeResponseCodeIs(200);
            $I->seeElement('nav.pager');

            $I->amOnPage($prefix . self::STORE_ID . '?page=2');
            $I->seeResponseCodeIs(200);
        }
    }

    /** Order Testing must be untouched by any of this: no checkboxes, no topic column, no filter. */
    public function theOrderTestingPageGainsNothing(WebTester $I): void
    {
        $I->amOnPage('/order-testing/store/' . self::STORE_ID);

        $I->seeResponseCodeIs(200);
        $I->see('Order No#', 'thead');
        $I->dontSee('Set Topic', 'thead');
        $I->dontSeeElement('[data-practice-select-all]');
        $I->dontSeeElement('#practice-bulk-form');
        $I->dontSeeElement('.practice-filter');
        $I->dontSeeElement('select[name="topic"]');
    }

    /**
     * And Order Testing ignores the parameter entirely.
     *
     * Its own surface never emits one, but a reader who edits a URL — or a link pasted from the other
     * surface — must get the page Order Testing has always rendered, not a filtered one.
     */
    public function orderTestingIgnoresATopicParameter(WebTester $I): void
    {
        $topicId = $this->seedTopic();
        $this->assignDirectly($topicId);

        $I->amOnPage('/order-testing/store/' . self::STORE_ID . '?topic=none');

        $I->seeResponseCodeIs(200);
        $I->see((string) self::ORDER, 'tbody');
        $I->see((string) self::ORDER_B, 'tbody');
    }

    public function aGuestCannotReachPracticeOrders(WebTester $I): void
    {
        $I->resetCookie('KFSESSID');

        $I->amOnPage('/practice-orders');
        $I->seeInCurrentUrl('/login');

        $I->amOnPage('/practice-orders/store/' . self::STORE_ID);
        $I->seeInCurrentUrl('/login');
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
            $publicId = str_pad('page' . $i, 32, '0', STR_PAD_LEFT);

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
                'public_id' => str_pad('job' . $i, 32, '0', STR_PAD_LEFT),
                'conversation_id' => $conversationId,
                'uploaded_by_admin_id' => $adminId,
                'original_filename' => 'page.wav',
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
            'original_filename' => 'practice.wav',
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
            ->delete('{{%order58_orders}}', ['account_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%knowledge_bases}}', ['source_store_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%order58_stores}}', ['source_id' => self::STORE_ID])->execute();
        $this->connection->createCommand()
            ->delete('{{%admin_users}}', ['username' => self::USERNAME])->execute();
    }
}
