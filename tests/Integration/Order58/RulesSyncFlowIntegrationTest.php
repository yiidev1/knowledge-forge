<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Application\Mapper\RuleMapper;
use App\Order58\Application\Order58SyncParams;
use App\Order58\Application\Sync\RulesSyncHandler;
use App\Order58\Application\Sync\SyncOutcome;
use App\Order58\Contract\Dto\Order58Page;
use App\Order58\Contract\Dto\Order58Pagination;
use App\Order58\Contract\Dto\Order58RuleRecord;
use App\Order58\Domain\Order58SyncType;
use App\Order58\Domain\RuleMirror;
use App\Order58\Domain\SyncRunStatus;
use App\Order58\Infrastructure\DbOrder58RuleRepository;
use App\Order58\Infrastructure\DbSyncRunRepository;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseRepository;
use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\Rules\Application\RuleCatalogService;
use App\Rules\Application\RuleClassificationRunner;
use App\Rules\Application\RuleHasher;
use App\Rules\Application\RuleReconciliationRunner;
use App\Rules\Infrastructure\DbRuleCatalogRepository;
use App\Shared\Application\Transaction\TransactionalRunner;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\Fake\Order58\FakeOrder58Client;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\RulesTestFactory;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function sys_get_temp_dir;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The rules sync handler end-to-end against real MySQL, driven by a scripted Order58 client: a first sync
 * mirrors and deduplicates, pagination follows the response's total_pages, a second identical sync writes no new
 * rows (sync-hash skip), and mark-and-sweep — only after a complete scan — deactivates records missing upstream.
 */
final class RulesSyncFlowIntegrationTest extends Unit
{
    private const TITLE_MARK = 'ZZFLOW';
    private const ADMIN = 972000000;
    private const SOURCE_LO = 972000001;
    private const SOURCE_HI = 972000099;

    private ConnectionInterface $connection;
    private DbOrder58RuleRepository $rules;
    private RuleCatalogService $catalogService;
    private RuleClassificationRunner $classifier;
    private DbSyncRunRepository $runs;
    private Order58SyncParams $params;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->rules = new DbOrder58RuleRepository($this->connection);
        $catalogRepo = new DbRuleCatalogRepository($this->connection);
        $this->catalogService = new RuleCatalogService($catalogRepo, new RuleHasher(), new TransactionalRunner($this->connection));
        $this->classifier = RulesTestFactory::classificationRunner($this->connection, sys_get_temp_dir() . '/kf_rules_flow');

        $this->runs = new DbSyncRunRepository($this->connection);
        $this->params = new Order58SyncParams(pageSize: 100, maxAttempts: 3, pagesPerRun: 1000);
        $this->now = new DateTimeImmutable('2026-08-05 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testFirstSyncMirrorsAndDeduplicatesThenSecondSyncAddsNothing(): void
    {
        $client = $this->scriptedClient();

        $first = $this->runSync($client);

        assertSame(SyncRunStatus::Completed, $first->terminalStatus);
        assertSame([1, 2], $client->listRulesCalls, 'pagination followed total_pages (two pages)');
        assertSame(3, $first->progress->created, 'three raw source rules created');
        assertSame(2, $first->progress->canonicalCreated, 'two canonical rules (the duplicate collapses)');
        assertSame(1, $first->progress->exactDuplicates, 'one exact-duplicate source link');
        assertSame(3, $this->sourceCount(), 'three raw rows mirrored');
        assertSame(2, $this->canonicalCount(), 'two canonical rows');
        // The two identical-content rules share one canonical; the second is an exact duplicate.
        assertSame(
            $this->catalogFor(self::SOURCE_LO),
            $this->catalogFor(self::SOURCE_LO + 1),
        );

        // A second, identical sync must not create any new rows.
        $second = $this->runSync($this->scriptedClient());

        assertSame(SyncRunStatus::Completed, $second->terminalStatus);
        assertSame(0, $second->progress->created);
        assertSame(3, $second->progress->unchanged, 'all three rules unchanged by sync hash');
        assertSame(0, $second->progress->canonicalCreated);
        assertSame(3, $this->sourceCount(), 'still three raw rows — no duplicates');
        assertSame(2, $this->canonicalCount(), 'still two canonical rows');
    }

    public function testSweepDeactivatesRecordsMissingFromTheLatestSync(): void
    {
        // A record from a prior run that the upstream no longer returns. Its last-seen marker is cleared to
        // NULL so it is unambiguously "not seen" by the upcoming run, regardless of that run's id.
        $stale = self::SOURCE_LO + 50;
        $this->rules->save($this->mirror($stale, 'ZZFLOW stale', 'Gone upstream', 'hs'), 1, $this->now);
        $this->connection->createCommand()
            ->update('{{%order58_rule_records}}', ['last_seen_sync_run_id' => null], ['source_id' => $stale])
            ->execute();
        assertSame(1, $this->activeOf($stale));

        $this->runSync($this->scriptedClient());

        assertSame(0, $this->activeOf($stale), 'a record missing from a complete sync is soft-deactivated');
        assertSame(1, $this->activeOf(self::SOURCE_LO), 'a record present this run stays active');
    }

    public function testUnchangedStaleInactiveRuleIsReactivatedAndProjected(): void
    {
        $first = $this->runSync($this->scriptedClient());
        assertSame(SyncRunStatus::Completed, $first->terminalStatus);

        // Simulate the historical bug: content still matches upstream, but is_active stuck at 0 and the
        // canonical was recomputed inactive. Next successful sync must restore lifecycle without rewriting
        // content, then auto-reconcile global projections.
        foreach ([self::SOURCE_LO, self::SOURCE_LO + 1, self::SOURCE_LO + 2] as $sourceId) {
            $this->connection->createCommand()
                ->update('{{%order58_rule_records}}', ['is_active' => 0], ['source_id' => $sourceId])
                ->execute();
            $recordId = $this->rules->findIdBySourceId($sourceId);
            assertNotNull($recordId);
            $this->catalogService->recomputeActiveForRecord($recordId, $this->now);
            assertSame(0, $this->activeOf($sourceId));
        }

        $second = $this->runSync($this->scriptedClient());

        assertSame(SyncRunStatus::Completed, $second->terminalStatus);
        assertSame(0, $second->progress->created);
        assertSame(0, $second->progress->updated);
        assertSame(3, $second->progress->unchanged);
        assertSame(1, $this->activeOf(self::SOURCE_LO));
        assertSame(1, $this->activeOf(self::SOURCE_LO + 1));
        assertSame(1, $this->activeOf(self::SOURCE_LO + 2));
        assertSame(2, $this->activeCanonicalCount(), 'canonical activity restored from active sources');

        $kb = (new EnsureCommonRulesKnowledgeBaseService(
            new DbKnowledgeBaseRepository($this->connection, new SystemClock()),
        ))->find();
        assertNotNull($kb, 'successful sync auto-ensures the hidden shared_rules KB');
        $globalDocs = (int) $this->connection
            ->createQuery()
            ->from('{{%documents}}')
            ->where([
                'knowledge_base_id' => $kb->id(),
                'source_type' => 'order58_rule_global',
                'is_enabled' => 1,
            ])
            ->andWhere(['<>', 'status', 'deleted'])
            ->count();
        assertGreaterThanOrEqual(2, $globalDocs, 'active canonicals get global projections queued');
    }

    public function testPartialScanDoesNotDeactivateUnseenRules(): void
    {
        $this->runSync($this->scriptedClient());

        // An active rule from a prior complete sync that this partial page will not return.
        $unseen = self::SOURCE_LO + 60;
        $this->rules->save($this->mirror($unseen, 'ZZFLOW partial unseen', 'Still here', 'hp'), 1, $this->now);
        $this->connection->createCommand()
            ->update('{{%order58_rule_records}}', ['last_seen_sync_run_id' => 1, 'is_active' => 1], ['source_id' => $unseen])
            ->execute();
        assertSame(1, $this->activeOf($unseen));

        $params = new Order58SyncParams(pageSize: 100, maxAttempts: 3, pagesPerRun: 1);
        $client = $this->scriptedClient();
        $catalogRepo = new DbRuleCatalogRepository($this->connection);
        $handler = new RulesSyncHandler(
            $client,
            $this->rules,
            new RuleMapper(),
            $this->catalogService,
            $this->classifier,
            new RuleReconciliationRunner(
                $catalogRepo,
                RulesTestFactory::reconciler($this->connection, sys_get_temp_dir() . '/kf_rules_flow'),
                new SystemClock(),
            ),
            $this->runs,
            $params,
        );

        $runId = $this->runs->enqueue(Order58SyncType::Rules, null, self::ADMIN, $this->now);
        assertNotNull($runId);
        assertTrue($this->runs->claim($runId, $this->now));
        $run = $this->runs->findById($runId);
        assertNotNull($run);

        $outcome = $handler->handle($run, $this->now);
        assertSame(null, $outcome->terminalStatus, 'partial yield — more pages remain');
        assertSame(1, $this->activeOf($unseen), 'partial/failed scan must not deactivate unseen rows');
    }

    public function testUnchangedResyncDoesNotEmitClassificationEvents(): void
    {
        $this->runSync($this->scriptedClient());
        $eventsBefore = $this->classificationEventCount();

        $second = $this->runSync($this->scriptedClient());
        assertSame(3, $second->progress->unchanged);

        assertSame($eventsBefore, $this->classificationEventCount(), 'unchanged content must not reclassify');
    }

    private function runSync(FakeOrder58Client $client): SyncOutcome
    {
        $catalogRepo = new DbRuleCatalogRepository($this->connection);
        $handler = new RulesSyncHandler(
            $client,
            $this->rules,
            new RuleMapper(),
            $this->catalogService,
            $this->classifier,
            new RuleReconciliationRunner(
                $catalogRepo,
                RulesTestFactory::reconciler($this->connection, sys_get_temp_dir() . '/kf_rules_flow'),
                new SystemClock(),
            ),
            $this->runs,
            $this->params,
        );

        $runId = $this->runs->enqueue(Order58SyncType::Rules, null, self::ADMIN, $this->now);
        assertNotNull($runId);
        assertTrue($this->runs->claim($runId, $this->now));
        $run = $this->runs->findById($runId);
        assertNotNull($run);

        $outcome = $handler->handle($run, $this->now);

        // Simulate the drainer finalising the run so the coalescing active_key frees for the next sync.
        $this->runs->finish(
            $runId,
            $outcome->terminalStatus ?? SyncRunStatus::Completed,
            $outcome->progress,
            $outcome->errorCode,
            $outcome->errorMessage,
            $this->now,
        );

        return $outcome;
    }

    /**
     * Two pages: page 1 has two identical-content rules (one is an exact duplicate); page 2 has a distinct rule.
     */
    private function scriptedClient(): FakeOrder58Client
    {
        $client = new FakeOrder58Client();
        $client->rulePages = [
            1 => new Order58Page([
                $this->rule(self::SOURCE_LO, self::TITLE_MARK . ' A', 'Shared body', 'h1'),
                $this->rule(self::SOURCE_LO + 1, self::TITLE_MARK . ' A', 'Shared body', 'h2'),
            ], new Order58Pagination(1, 100, 3, 2)),
            2 => new Order58Page([
                $this->rule(self::SOURCE_LO + 2, self::TITLE_MARK . ' B', 'Other body', 'h3'),
            ], new Order58Pagination(2, 100, 3, 2)),
        ];

        return $client;
    }

    private function rule(int $sourceId, string $title, string $description, string $hash): Order58RuleRecord
    {
        return new Order58RuleRecord(
            id: $sourceId,
            type: 'Rule',
            title: $title,
            description: $description,
            ruleKeyword: null,
            createdName: 'admin2',
            sourceStoreId: null,
            createdAt: null,
            updatedAt: null,
            syncHash: $hash,
            raw: ['id' => $sourceId, 'title' => $title, 'description' => $description, '_sync_hash' => $hash],
        );
    }

    private function mirror(int $sourceId, string $title, string $description, string $hash): RuleMirror
    {
        return new RuleMirror(
            id: null,
            sourceId: $sourceId,
            type: 'Rule',
            title: $title,
            description: $description,
            ruleKeyword: null,
            createdName: 'admin2',
            sourceStoreId: null,
            active: true,
            syncHash: $hash,
            sourceCreatedAt: null,
            sourceUpdatedAt: null,
            snapshot: ['id' => $sourceId],
        );
    }

    private function catalogFor(int $sourceId): ?int
    {
        $recordId = $this->rules->findIdBySourceId($sourceId);

        return $recordId === null ? null : (new DbRuleCatalogRepository($this->connection))->findCanonicalIdForRecord($recordId);
    }

    private function activeOf(int $sourceId): int
    {
        return (int) $this->connection
            ->createQuery()
            ->select('is_active')
            ->from('{{%order58_rule_records}}')
            ->where(['source_id' => $sourceId])
            ->scalar();
    }

    private function sourceCount(): int
    {
        return (int) $this->connection
            ->createQuery()
            ->from('{{%order58_rule_records}}')
            ->where(['between', 'source_id', self::SOURCE_LO, self::SOURCE_HI])
            ->count();
    }

    private function canonicalCount(): int
    {
        return (int) $this->connection
            ->createQuery()
            ->from('{{%rule_catalog_rules}}')
            ->where(['like', 'title', self::TITLE_MARK])
            ->count();
    }

    private function activeCanonicalCount(): int
    {
        return (int) $this->connection
            ->createQuery()
            ->from('{{%rule_catalog_rules}}')
            ->where(['and', ['like', 'title', self::TITLE_MARK], ['is_active' => 1]])
            ->count();
    }

    private function classificationEventCount(): int
    {
        return (int) $this->connection->createCommand(
            'SELECT COUNT(*) FROM {{%rule_classification_events}} [[e]]'
            . ' JOIN {{%rule_catalog_rules}} [[k]] ON [[k]].[[id]] = [[e]].[[rule_catalog_rule_id]]'
            . ' WHERE [[k]].[[title]] LIKE :mark',
            [':mark' => self::TITLE_MARK . '%'],
        )->queryScalar();
    }

    private function cleanup(): void
    {
        // Projections created by post-sync reconcile for this fixture's canonicals.
        $this->connection->createCommand(
            'DELETE [[d]] FROM {{%documents}} [[d]]'
            . ' JOIN {{%rule_catalog_rules}} [[k]] ON [[k]].[[id]] = CAST([[d]].[[source_ref]] AS UNSIGNED)'
            . ' WHERE [[k]].[[title]] LIKE :mark'
            . ' AND [[d]].[[source_type]] IN (\'order58_rule_global\', \'order58_rule_common\', \'order58_rule_store\')',
            [':mark' => self::TITLE_MARK . '%'],
        )->execute();

        // Links/events reference the canonical rules with RESTRICT, so remove them first.
        foreach (['{{%rule_store_links}}', '{{%rule_classification_events}}'] as $child) {
            $this->connection->createCommand(
                'DELETE [[c]] FROM ' . $child . ' [[c]]'
                . ' JOIN {{%rule_catalog_rules}} [[k]] ON [[k]].[[id]] = [[c]].[[rule_catalog_rule_id]]'
                . ' WHERE [[k]].[[title]] LIKE :mark',
                [':mark' => self::TITLE_MARK . '%'],
            )->execute();
        }
        $this->connection->createCommand(
            'DELETE [[s]] FROM {{%rule_catalog_sources}} [[s]]'
            . ' JOIN {{%order58_rule_records}} [[r]] ON [[r]].[[id]] = [[s]].[[order58_rule_record_id]]'
            . ' WHERE [[r]].[[source_id]] BETWEEN :lo AND :hi',
            [':lo' => self::SOURCE_LO, ':hi' => self::SOURCE_HI],
        )->execute();
        IntegrationDb::cleanup($this->connection, '{{%rule_catalog_rules}}', ['like', 'title', self::TITLE_MARK]);
        $this->connection->createCommand(
            'DELETE FROM {{%order58_rule_records}} WHERE [[source_id]] BETWEEN :lo AND :hi',
            [':lo' => self::SOURCE_LO, ':hi' => self::SOURCE_HI],
        )->execute();
        IntegrationDb::cleanup($this->connection, '{{%integration_sync_runs}}', ['requested_by_admin_id' => self::ADMIN]);
    }
}
