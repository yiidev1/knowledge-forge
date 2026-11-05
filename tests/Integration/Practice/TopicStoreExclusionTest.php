<?php

declare(strict_types=1);

namespace App\Tests\Integration\Practice;

use App\Practice\Application\TopicInputValidator;
use App\Practice\Application\TopicService;
use App\Practice\Domain\ExcludedStoreIds;
use App\Practice\Domain\StoreOption;
use App\Practice\Domain\TopicStatus;
use App\Practice\Infrastructure\DbStoreOptions;
use App\Practice\Infrastructure\DbTopicRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_map;
use function gmdate;
use function str_repeat;

/**
 * Test and demo accounts are never offered for a new topic store, and the server refuses them too.
 *
 * The excluded list is passed in here exactly as `PRACTICE_TOPIC_EXCLUDED_STORE_IDS` is wired in
 * production, so the dropdown's source ({@see DbStoreOptions::all()}) and the server's check
 * ({@see TopicService}) are tested against one definition. A topic saved against such an account before
 * it was excluded keeps its store: editing it neither moves it to Global nor refuses the save.
 */
final class TopicStoreExclusionTest extends Unit
{
    private const REAL = 987655962;
    private const TEST_ACCOUNT = 987655963;
    private const DEMO_ACCOUNT = 987655964;
    private const STORES = [self::REAL, self::TEST_ACCOUNT, self::DEMO_ACCOUNT];

    private const NAMES = ['ZZ Excl Global', 'ZZ Excl Refused', 'ZZ Excl Kept', 'ZZ Excl Renamed', 'ZZ Excl Moved'];

    private ConnectionInterface $connection;
    private DbTopicRepository $topics;
    private DbStoreOptions $stores;
    private TopicService $service;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->topics = new DbTopicRepository($this->connection, new SystemClock());
        $this->stores = new DbStoreOptions(
            $this->connection,
            ExcludedStoreIds::fromSetting(self::TEST_ACCOUNT . ',' . self::DEMO_ACCOUNT),
        );
        $this->service = new TopicService($this->topics, new TopicInputValidator(), $this->stores);

        $this->seedStore(self::REAL, 'ZZ Exclusion Real Store');
        $this->seedStore(self::TEST_ACCOUNT, 'ZZ Exclusion 0000000009 Test');
        $this->seedStore(self::DEMO_ACCOUNT, 'ZZ Exclusion Demo XYZ');
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    // ---------------------------------------------------------------- the dropdown

    public function testTheDropdownOffersRealStoresAndNotTheExcludedAccounts(): void
    {
        $offered = $this->ids($this->stores->all());

        self::assertContains(self::REAL, $offered);
        self::assertNotContains(self::TEST_ACCOUNT, $offered);
        self::assertNotContains(self::DEMO_ACCOUNT, $offered);
    }

    /** The topic being edited keeps its own store in the list, and only that one comes back. */
    public function testTheEditedTopicsOwnStoreStaysInTheList(): void
    {
        $offered = $this->ids($this->stores->all(self::DEMO_ACCOUNT));

        self::assertContains(self::DEMO_ACCOUNT, $offered);
        self::assertNotContains(self::TEST_ACCOUNT, $offered);
        self::assertContains(self::REAL, $offered);
    }

    public function testOfferedIsNarrowerThanExists(): void
    {
        self::assertTrue($this->stores->isOffered(self::REAL));
        self::assertFalse($this->stores->isOffered(self::TEST_ACCOUNT));
        self::assertTrue($this->stores->exists(self::TEST_ACCOUNT), 'the account is still a real mirror row');
        self::assertFalse($this->stores->isOffered(987655969), 'an unknown store is not offered either');
    }

    /** The listing still names an excluded account's store; only new choices are narrowed. */
    public function testTheListingStillNamesAnExcludedAccount(): void
    {
        self::assertSame(
            [self::DEMO_ACCOUNT => 'ZZ Exclusion Demo XYZ'],
            $this->stores->namesFor([self::DEMO_ACCOUNT]),
        );
    }

    /** With nothing configured, nothing is excluded — the behaviour before this setting existed. */
    public function testAnEmptySettingExcludesNothing(): void
    {
        $offered = $this->ids((new DbStoreOptions($this->connection))->all());

        self::assertContains(self::TEST_ACCOUNT, $offered);
        self::assertContains(self::DEMO_ACCOUNT, $offered);
    }

    // ---------------------------------------------------------------- the server

    public function testCreatingATopicOnAnExcludedAccountIsRefused(): void
    {
        try {
            $this->service->create('ZZ Excl Refused', null, true, null, self::TEST_ACCOUNT);
            self::fail('an excluded account must be refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('store_source_id', $e->errors());
            self::assertStringContainsString('test or demo account', $e->errors()['store_source_id']);
        }

        self::assertSame(0, $this->countNamed('ZZ Excl Refused'));
    }

    public function testGlobalStillWorks(): void
    {
        $id = $this->service->create('ZZ Excl Global', null, true, null, null);

        self::assertNull($this->topics->find($id)?->storeSourceId);
    }

    /** A topic saved on an account before it was excluded: renaming keeps it exactly where it is. */
    public function testATopicAlreadyOnAnExcludedAccountCanBeEditedWithoutMoving(): void
    {
        $id = $this->topics->create('ZZ Excl Kept', null, TopicStatus::Active, null, self::DEMO_ACCOUNT);

        // The form posts the store it shows — the kept one — with the change.
        $this->service->update($id, 'ZZ Excl Renamed', 'New words', null, self::DEMO_ACCOUNT, changeStore: true);

        $topic = $this->topics->find($id);
        self::assertSame('ZZ Excl Renamed', $topic?->name);
        self::assertSame(self::DEMO_ACCOUNT, $topic?->storeSourceId);
    }

    /** ... but it may not be moved onto a different excluded account. */
    public function testATopicCannotBeMovedOntoAnotherExcludedAccount(): void
    {
        $id = $this->topics->create('ZZ Excl Kept', null, TopicStatus::Active, null, self::DEMO_ACCOUNT);

        $this->expectException(ValidationException::class);
        $this->service->update($id, 'ZZ Excl Kept', null, null, self::TEST_ACCOUNT, changeStore: true);
    }

    /** Moving it off the excluded account onto a real store is an ordinary choice. */
    public function testATopicCanBeMovedFromAnExcludedAccountToARealStore(): void
    {
        $id = $this->topics->create('ZZ Excl Moved', null, TopicStatus::Active, null, self::DEMO_ACCOUNT);

        $this->service->update($id, 'ZZ Excl Moved', null, null, self::REAL, changeStore: true);

        self::assertSame(self::REAL, $this->topics->find($id)?->storeSourceId);
    }

    public function testAGlobalTopicCannotBeNarrowedToAnExcludedAccount(): void
    {
        $id = $this->service->create('ZZ Excl Global', null, true, null, null);

        try {
            $this->service->update($id, 'ZZ Excl Global', null, null, self::DEMO_ACCOUNT, changeStore: true);
            self::fail('an excluded account must be refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('store_source_id', $e->errors());
        }

        self::assertNull($this->topics->find($id)?->storeSourceId, 'the topic stays global');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param list<StoreOption> $options
     * @return list<int>
     */
    private function ids(array $options): array
    {
        return array_map(static fn(StoreOption $o): int => $o->sourceId, $options);
    }

    private function countNamed(string $name): int
    {
        return (int) $this->connection->createCommand(
            'SELECT COUNT(*) FROM {{%practice_topics}} WHERE name = :n',
            [':n' => $name],
        )->queryScalar();
    }

    private function seedStore(int $sourceId, string $name): void
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
    }

    /** Scoped to this test's own stores and names; never a blanket delete. */
    private function cleanup(): void
    {
        $this->connection->createCommand()->delete('{{%practice_topics}}', ['name' => self::NAMES])->execute();
        $this->connection->createCommand()->delete('{{%order58_stores}}', ['source_id' => self::STORES])->execute();
    }
}
