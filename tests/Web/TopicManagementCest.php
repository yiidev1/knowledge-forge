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

/**
 * Topic Management, against the real served application.
 *
 * The things worth asserting here are the ones the unit and integration tests cannot see: that the
 * dialog really opens from the URL, that the Edit link reaches the right topic's values, that a
 * rejected submission comes back with the dialog still open and what was typed still in it, and that
 * deleting says out loud how many orders kept the topic.
 *
 * A fixture administrator is created per run; topics use a distinctive prefix and are cleaned up by
 * name, never by truncation, so a shared development database survives.
 */
use function substr_count;

final class TopicManagementCest
{
    private const USERNAME = '__kf_topics_admin__';
    private const PASSWORD = 'TopicsTestPassw0rd!secure';
    private const PREFIX = 'ZZ Topic ';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));

        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    public function thePageListsTopicsAndOffersTheAddDialog(WebTester $I): void
    {
        $I->amOnPage('/admin/topics');
        $I->seeResponseCodeIs(200);
        $I->see('Topic Management', '.page-header__title');
        $I->see('Add Topic', '.page-header__actions');
        $I->seeElement('#practice-topic-dialog');
    }

    /** The dialog is state in the URL, which is what makes it work without JavaScript. */
    public function theDialogOpensFromTheUrlRatherThanOnlyFromAClick(WebTester $I): void
    {
        $I->amOnPage('/admin/topics');
        $I->dontSeeElement('#practice-topic-dialog[open]');

        $I->amOnPage('/admin/topics?topic=new');
        $I->seeElement('#practice-topic-dialog[open]');
        $I->see('Add Topic', '.source-modal__title');
    }

    public function anAdminCreatesATopicAndSeesItInTheList(WebTester $I): void
    {
        $I->amOnPage('/admin/topics?topic=new');
        $I->submitForm('#practice-topic-dialog form', [
            'name' => self::PREFIX . 'Change Dish',
            'description' => 'Customer changes their mind about a dish.',
            'status' => 'ACTIVE',
        ]);

        $I->seeCurrentUrlEquals('/admin/topics');
        $I->see('created', '.alert');
        $I->see(self::PREFIX . 'Change Dish', '.practice-topics');
        $I->see('Active', '.practice-topics');
    }

    /**
     * The duplicate rule, as a person meets it.
     *
     * Different case and different spacing, which is exactly the spelling a second trainer would use.
     * The page must come back with the dialog open, the message under the name field, and the typed
     * value still there — not an empty form and a flash at the top.
     */
    public function aDuplicateNameIsRefusedWithTheDialogStillOpenAndTheTypingKept(WebTester $I): void
    {
        $this->seedTopic('Change Dish');

        $I->amOnPage('/admin/topics?topic=new');
        $I->submitForm('#practice-topic-dialog form', [
            'name' => '  ' . strtoupper(self::PREFIX . 'change   dish') . ' ',
            'description' => 'Typed again by somebody else.',
            'status' => 'ACTIVE',
        ]);

        $I->seeResponseCodeIs(200);
        $I->seeElement('#practice-topic-dialog[open]');
        $I->see('already exists', '.field__error');
        // What comes back is what the server kept: FormData::string() trims, so the outer spaces are
        // gone while the typed casing and inner spacing survive for the operator to correct.
        $I->seeInField('#practice-topic-name', strtoupper(self::PREFIX . 'change   dish'));
        $I->seeInField('#practice-topic-description', 'Typed again by somebody else.');
    }

    public function anEmptyNameIsRefusedOnItsOwnField(WebTester $I): void
    {
        $I->amOnPage('/admin/topics?topic=new');
        $I->submitForm('#practice-topic-dialog form', ['name' => '   ', 'description' => '', 'status' => 'ACTIVE']);

        $I->seeElement('#practice-topic-dialog[open]');
        $I->see('Enter a topic name', '.field__error');
    }

    public function renamingATopicKeepsItsIdentity(WebTester $I): void
    {
        $id = $this->seedTopic('Before');

        $I->amOnPage('/admin/topics?topic=' . $id);
        $I->seeElement('#practice-topic-dialog[open]');
        $I->see('Edit Topic', '.source-modal__title');
        $I->seeInField('#practice-topic-name', self::PREFIX . 'Before');

        $I->submitForm('#practice-topic-dialog form', [
            'name' => self::PREFIX . 'After',
            'description' => '',
        ]);

        $I->see(self::PREFIX . 'After', '.practice-topics');
        $I->dontSee(self::PREFIX . 'Before', '.practice-topics');
        Assert::assertSame(self::PREFIX . 'After', $this->nameOf($id), 'the row should have been renamed, not replaced');
    }

    public function deactivatingAndActivatingATopicRoundTrips(WebTester $I): void
    {
        $id = $this->seedTopic('Toggle');

        $I->amOnPage('/admin/topics');
        $I->submitForm('form[action="/admin/topics/' . $id . '/status"]', []);
        $I->see('deactivated', '.alert');
        Assert::assertSame('INACTIVE', $this->statusOf($id));

        $I->submitForm('form[action="/admin/topics/' . $id . '/status"]', []);
        $I->see('activated', '.alert');
        Assert::assertSame('ACTIVE', $this->statusOf($id));
    }

    /** Delete keeps the row — the training record — and the message says what was kept. */
    public function deletingKeepsTheRowAndSaysSo(WebTester $I): void
    {
        $id = $this->seedTopic('Retired');

        $I->amOnPage('/admin/topics');
        $I->submitForm('form[action="/admin/topics/' . $id . '/delete"]', []);

        $I->see('deleted successfully', '.alert');
        Assert::assertSame('ARCHIVED', $this->statusOf($id), 'deleting must not destroy the row');

        // Gone from the list, and not reachable by hand-editing the status filter either: the storage's
        // word for the delete is not a view anybody can ask for.
        $I->amOnPage('/admin/topics');
        $I->dontSee(self::PREFIX . 'Retired', '.practice-topics');

        $I->amOnPage('/admin/topics?status=ARCHIVED');
        $I->dontSee(self::PREFIX . 'Retired', '.practice-topics');
    }

    /** A deleted topic keeps the orders that carry it, and cannot be assigned to new ones. */
    public function aDeletedTopicKeepsItsHistoryAndLeavesThePickers(WebTester $I): void
    {
        $id = $this->seedTopic('Retired');
        $this->assignTo($id, 987655830, 16800999);

        $I->amOnPage('/admin/topics');
        $I->submitForm('form[action="/admin/topics/' . $id . '/delete"]', []);

        $I->see('1 order', '.alert');
        Assert::assertSame(
            1,
            $this->assignmentCount($id),
            'the assignment is the training record and must survive the delete',
        );
        Assert::assertSame('ARCHIVED', $this->statusOf($id));
    }

    /** Archive is gone from the interface entirely: no button, no chip, no endpoint link. */
    public function theInterfaceNeverOffersArchive(WebTester $I): void
    {
        $this->seedTopic('Visible');

        $I->amOnPage('/admin/topics');

        $I->dontSee('Archive', '.practice-topics');
        $I->dontSee('Archived', '.filter-bar');
        $I->dontSeeElement('form[action$="/archive"]');

        // The three chips that are offered, and only those three.
        $I->see('All Topics', '.filter-bar');
        $I->see('Active', '.filter-bar');
        $I->see('Inactive', '.filter-bar');
    }

    /**
     * **The bug this suite missed.**
     *
     * Clicking Edit used to open the dialog that was already on the page — the Add form — because the
     * link it carried had lost its `?topic=` on the way out of the URL builder. Following the real link
     * must land on that topic's own values.
     */
    public function theEditLinkCarriesTheTopicAndPrefillsIt(WebTester $I): void
    {
        $id = $this->seedTopic('Prefill', 'INACTIVE');
        $this->describe($id, 'What the operator typed when they created it.');

        $I->amOnPage('/admin/topics');
        $I->seeElement('a[href="/admin/topics?topic=' . $id . '"]');
        $I->click('a[href="/admin/topics?topic=' . $id . '"]');

        $I->seeInCurrentUrl('topic=' . $id);
        $I->seeElement('#practice-topic-dialog[open]');
        $I->see('Edit Topic', '.source-modal__title');
        $I->seeInField('#practice-topic-name', self::PREFIX . 'Prefill');
        $I->seeInField('#practice-topic-description', 'What the operator typed when they created it.');
        $I->seeOptionIsSelected('#practice-topic-status', 'Inactive — kept, but not offered for new orders');
    }

    /** One topic's values must never appear under another topic's name. */
    public function editingOneTopicThenAnotherShowsEachOnesOwnValues(WebTester $I): void
    {
        $first = $this->seedTopic('First');
        $this->describe($first, 'The first description.');
        $second = $this->seedTopic('Second');
        $this->describe($second, 'The second description.');

        $I->amOnPage('/admin/topics?topic=' . $first);
        $I->seeInField('#practice-topic-name', self::PREFIX . 'First');
        $I->seeInField('#practice-topic-description', 'The first description.');

        $I->amOnPage('/admin/topics?topic=' . $second);
        $I->seeInField('#practice-topic-name', self::PREFIX . 'Second');
        $I->seeInField('#practice-topic-description', 'The second description.');
        $I->dontSeeInField('#practice-topic-description', 'The first description.');
    }

    /** The Add dialog starts empty, whatever was edited before it. */
    public function theAddDialogStartsOnNewRecordDefaults(WebTester $I): void
    {
        $id = $this->seedTopic('Existing');
        $this->describe($id, 'Belongs to the existing one.');

        $I->amOnPage('/admin/topics?topic=' . $id);
        $I->seeInField('#practice-topic-name', self::PREFIX . 'Existing');

        $I->amOnPage('/admin/topics?topic=new');
        $I->see('Add Topic', '.source-modal__title');
        $I->seeInField('#practice-topic-name', '');
        $I->seeInField('#practice-topic-description', '');
        $I->seeOptionIsSelected('#practice-topic-status', 'Active — can be assigned to orders');
    }

    /** Saving the edit writes to the record that was opened, status included. */
    public function savingAnEditUpdatesThatRecord(WebTester $I): void
    {
        $id = $this->seedTopic('Editable');
        $other = $this->seedTopic('Untouched');

        $I->amOnPage('/admin/topics?topic=' . $id);
        $I->submitForm('#practice-topic-dialog form', [
            'name' => self::PREFIX . 'Edited',
            'description' => 'Rewritten.',
            'status' => 'INACTIVE',
        ]);

        $I->see('updated', '.alert');
        Assert::assertSame(self::PREFIX . 'Edited', $this->nameOf($id));
        Assert::assertSame('INACTIVE', $this->statusOf($id));
        Assert::assertSame(self::PREFIX . 'Untouched', $this->nameOf($other), 'the other row must be untouched');
        Assert::assertSame('ACTIVE', $this->statusOf($other));
    }

    /** Active and Inactive are told apart by a badge, not by reading the word alone. */
    public function theStatusIsShownAsAColouredBadge(WebTester $I): void
    {
        $this->seedTopic('Green');
        $this->seedTopic('Red', 'INACTIVE');

        $I->amOnPage('/admin/topics');

        $I->see('Active', '.practice-topics .badge--success');
        $I->see('Inactive', '.practice-topics .badge--error');
    }

    /**
     * An active topic is offered Deactivate; an inactive one, Activate. Never both.
     *
     * Asserted on the value the form would post and the class the button carries, not on its text:
     * `see()` is case-insensitive here, so "Deactivate" contains "activate" and a text assertion
     * cannot tell the two apart.
     */
    public function eachRowOffersOnlyTheStatusActionThatApplies(WebTester $I): void
    {
        $active = $this->seedTopic('Running');
        $form = 'form[action="/admin/topics/' . $active . '/status"]';

        $I->amOnPage('/admin/topics?q=' . urlencode(self::PREFIX . 'Running'));
        $I->seeElement($form . ' input[name="status"][value="INACTIVE"]');
        $I->dontSeeElement($form . ' input[name="status"][value="ACTIVE"]');
        $I->seeElement($form . ' button.btn--warning');

        $I->submitForm($form, []);
        Assert::assertSame('INACTIVE', $this->statusOf($active));

        $I->amOnPage('/admin/topics?q=' . urlencode(self::PREFIX . 'Running'));
        $I->seeElement($form . ' input[name="status"][value="ACTIVE"]');
        $I->dontSeeElement($form . ' input[name="status"][value="INACTIVE"]');
        $I->seeElement($form . ' button.btn--success');
    }

    /** The topic name is its own control, and opens the same read-only dialog the order rows use. */
    public function theTopicNameOpensTheDetailsDialog(WebTester $I): void
    {
        $id = $this->seedTopic('Described');
        $this->describe($id, 'What this topic covers, at length.');

        $I->amOnPage('/admin/topics?q=' . urlencode(self::PREFIX . 'Described'));

        $I->seeElement('.practice-topics [data-practice-topic-details]');
        $I->seeElement('[data-practice-topic-details][data-practice-topic-name="' . self::PREFIX . 'Described"]');
        $I->seeElement('[data-practice-topic-details][data-practice-topic-description="What this topic covers, at length."]');
        $I->seeElement('[data-practice-topic-details][data-practice-topic-status="Active"]');
        // This page has already counted the assignments, so the dialog can show the number.
        $I->seeElement('[data-practice-topic-details][data-practice-topic-orders="0"]');

        // The dialog itself: present, and with nothing in it that could change anything.
        $I->seeElement('#practice-topic-details-dialog');
        $I->dontSeeElement('#practice-topic-details-dialog form');
        $I->see('Topic Details', '#practice-topic-details-dialog .source-modal__title');
        $I->see('Description', '#practice-topic-details-dialog');
    }

    /** A topic with no description carries an empty one; the dialog is what says "none provided". */
    public function aTopicWithNoDescriptionCarriesAnEmptyOne(WebTester $I): void
    {
        $this->seedTopic('Bare');

        $I->amOnPage('/admin/topics?q=' . urlencode(self::PREFIX . 'Bare'));

        $I->seeElement('[data-practice-topic-details][data-practice-topic-description=""]');
    }

    /** The headers the desktop layout depends on, named rather than left to a screenshot. */
    public function theTableCarriesItsSixHeadersAndAColumnForEach(WebTester $I): void
    {
        $this->seedTopic('Listed');

        $I->amOnPage('/admin/topics');

        $I->see('Topic Name', 'thead');
        // Six now: which store may use a topic is a fact about it, beside its name.
        $I->see('Store', 'thead');
        $I->see('Status', 'thead');
        $I->see('Assigned Orders', 'thead');
        $I->see('Created At', 'thead');
        $I->see('Actions', 'thead');

        /*
         * And one `<col>` per header, counted against each other rather than against a number.
         *
         * A colgroup one short does not leave the last column unsized — it shifts every width one
         * place left, which on the Manage Orders table put a 21rem width on the wrong column and
         * collapsed another to nothing. `'<th'` alone would also count `<thead>`, hence the two.
         */
        $source = $I->grabPageSource();
        $headers = substr_count($source, '<th ') + substr_count($source, '<th>');

        Assert::assertSame(
            $headers,
            substr_count($source, '<col class="practice-col-'),
            'The colgroup and the header row must describe the same columns.',
        );
    }

    /** A topic with no store reads as Global — which is what every topic was before the column. */
    public function theStoreColumnSaysGlobalForAnUnscopedTopic(WebTester $I): void
    {
        $this->seedTopic('Listed');

        $I->amOnPage('/admin/topics');

        $I->see('Global', '.practice-topics');
    }

    /** And the dialog offers the choice, defaulting to Global. */
    public function theDialogOffersTheStoreFieldDefaultingToGlobal(WebTester $I): void
    {
        $I->amOnPage('/admin/topics?topic=new');

        $I->see('Store', '.practice-dialog, dialog');
        $I->seeElement('select[name="store_source_id"]');
        $I->see('Global (All Stores)');
    }

    /**
     * The searchable picker is an upgrade, so what the server sends is still a working control.
     *
     * This is the half a browser test cannot make: PhpBrowser runs no script, so it sees exactly what
     * a reader with scripting off sees. If the select ever stops being rendered — replaced by markup
     * only the script understands — the field silently stops submitting for them, and this fails.
     */
    public function theStoreFieldStillWorksWithNoScript(WebTester $I): void
    {
        $I->amOnPage('/admin/topics?topic=new');

        // The host the script builds into, with the real control inside it rather than instead of it.
        $I->seeElement('[data-practice-store] select[data-practice-store-select][name="store_source_id"]');
        // Global is the empty value, and it is the one that is selected when nothing was chosen.
        $I->seeElement('select[name="store_source_id"] option[value=""]');
        // The label points at the control, and carries the id the picker reads it back by.
        $I->seeElement('label#practice-topic-store-label[for="practice-topic-store"]');

        /*
         * Every store is already in the page, which is what lets the search be instant and local.
         * Counted rather than named: the stores are the real mirrored ones, so any particular id is
         * a fact about today's data, while "the whole directory is here" is the actual requirement.
         */
        $options = $I->grabMultiple('select[name="store_source_id"] option', 'value');

        Assert::assertGreaterThan(
            50,
            count($options),
            'The store list must be rendered in full, not fetched on demand.',
        );
    }

    public function searchAndStatusFiltersComposeAndSurviveInTheForm(WebTester $I): void
    {
        $this->seedTopic('Alpha');
        $this->seedTopic('Beta');

        $I->amOnPage('/admin/topics?q=' . urlencode(self::PREFIX . 'Alpha'));
        $I->see(self::PREFIX . 'Alpha', '.practice-topics');
        $I->dontSee(self::PREFIX . 'Beta', '.practice-topics');

        // The status chip must carry the search with it rather than dropping it.
        $I->amOnPage('/admin/topics?q=' . urlencode(self::PREFIX . 'Alpha') . '&status=ACTIVE');
        $I->see(self::PREFIX . 'Alpha', '.practice-topics');
        $I->seeInField('input[name="q"]', self::PREFIX . 'Alpha');
    }

    /** A guest sees none of it, and lands on the login form rather than a 404. */
    public function aGuestCannotReachTopicManagement(WebTester $I): void
    {
        $I->resetCookie('KFSESSID');
        $I->amOnPage('/admin/topics');
        $I->seeInCurrentUrl('/login');
        $I->dontSee('Topic Management', '.page-header__title');
    }

    // ---------------------------------------------------------------- fixtures

    private function seedTopic(string $suffix, string $status = 'ACTIVE'): int
    {
        $name = self::PREFIX . $suffix;
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

    private function describe(int $id, string $description): void
    {
        $this->connection->createCommand()
            ->update('{{%practice_topics}}', ['description' => $description], ['id' => $id])->execute();
    }

    private function assignTo(int $topicId, int $storeSourceId, int $sourceOrderId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%practice_order_topics}}', [
            'store_source_id' => $storeSourceId,
            'source_order_id' => $sourceOrderId,
            'topic_id' => $topicId,
            'assigned_by_type' => 'ADMIN',
            'assigned_by_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    private function assignmentCount(int $topicId): int
    {
        return (int) (new Query($this->connection))
            ->from('{{%practice_order_topics}}')->where(['topic_id' => $topicId])->count();
    }

    private function statusOf(int $id): string
    {
        return (string) (new Query($this->connection))
            ->select('status')->from('{{%practice_topics}}')->where(['id' => $id])->scalar();
    }

    private function nameOf(int $id): string
    {
        return (string) (new Query($this->connection))
            ->select('name')->from('{{%practice_topics}}')->where(['id' => $id])->scalar();
    }

    /** Scoped to this suite's own prefix and its own administrator; never a blanket delete. */
    private function cleanup(): void
    {
        // The default `like` form wraps the value in wildcards. Passing `escape: false` with a
        // hand-written '%' matched nothing at all, which let every run inherit the last one's rows.
        $ids = (new Query($this->connection))
            ->select('id')->from('{{%practice_topics}}')
            ->where(['like', 'name', self::PREFIX])->column();

        if ($ids !== []) {
            $this->connection->createCommand()
                ->delete('{{%practice_order_topics}}', ['topic_id' => $ids])->execute();
            $this->connection->createCommand()
                ->delete('{{%practice_topics}}', ['id' => $ids])->execute();
        }

        $this->connection->createCommand()
            ->delete('{{%admin_users}}', ['username' => self::USERNAME])->execute();
    }
}
