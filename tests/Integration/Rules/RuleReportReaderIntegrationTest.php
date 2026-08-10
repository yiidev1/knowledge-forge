<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules;

use App\Order58\Contract\Dto\Order58RuleRecord;
use App\Order58\Domain\RuleMirror;
use App\Order58\Infrastructure\DbOrder58RuleRepository;
use App\Rules\Application\RuleCatalogService;
use App\Rules\Application\RuleHasher;
use App\Rules\Domain\ClassificationStatus;
use App\Rules\Domain\RuleReportFilter;
use App\Rules\Domain\RuleReportQuery;
use App\Rules\Domain\RuleScope;
use App\Rules\Domain\StoreMatchMethod;
use App\Rules\Domain\StoreMatchStatus;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseSourceRepository;
use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\Rules\Application\RuleProjectionReconciler;
use App\Rules\Infrastructure\DbRuleCatalogRepository;
use App\Rules\Infrastructure\DbRuleReportReader;
use App\Rules\Infrastructure\DbRuleStoreLinkRepository;
use App\Shared\Application\Transaction\TransactionalRunner;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\RulesTestFactory;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function str_pad;
use function substr;
use function sys_get_temp_dir;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use const STR_PAD_LEFT;

/**
 * The rules report reader against real MySQL. Because the summary reports global totals, assertions are on the
 * *deltas* produced by seeding sentinel data — robust to any pre-existing rows.
 */
final class RuleReportReaderIntegrationTest extends Unit
{
    private const SOURCE_LO = 976000001;
    private const SOURCE_HI = 976000099;
    private const TITLE_MARK = 'ZZRPT';

    private ConnectionInterface $connection;
    private DbOrder58RuleRepository $rules;
    private DbRuleCatalogRepository $catalog;
    private RuleCatalogService $catalogService;
    private DbRuleReportReader $reader;
    private RuleProjectionReconciler $reconciler;
    private DbKnowledgeBaseSourceRepository $kbSources;
    private DateTimeImmutable $now;
    private int $seq = 0;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->rules = new DbOrder58RuleRepository($this->connection);
        $this->catalog = new DbRuleCatalogRepository($this->connection);
        $this->catalogService = new RuleCatalogService($this->catalog, new RuleHasher(), new TransactionalRunner($this->connection));
        $this->reader = new DbRuleReportReader($this->connection);
        $this->reconciler = RulesTestFactory::reconciler($this->connection, sys_get_temp_dir() . '/kf_rules_report');
        $this->kbSources = new DbKnowledgeBaseSourceRepository($this->connection);
        $this->now = new DateTimeImmutable('2026-08-05 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testCatalogCountsAndReconciliationReflectLinkedSources(): void
    {
        $before = $this->reader->summary();

        // Two identical-content rules (one exact duplicate) + one distinct, all linked to the catalog.
        $this->linkedRecord('ZZRPT One', 'Shared body');
        $this->linkedRecord('ZZRPT One', 'Shared body');
        $this->linkedRecord('ZZRPT Two', 'Other body');

        $after = $this->reader->summary();

        assertSame(3, $after->sourceTotal - $before->sourceTotal, 'three source rules added');
        assertSame(3, $after->sourceActive - $before->sourceActive);
        assertSame(2, $after->canonicalActive - $before->canonicalActive, 'the duplicate collapses to one canonical');
        assertSame(1, $after->exactDuplicateSources - $before->exactDuplicateSources);
        assertSame(3, $after->accountedActiveSources - $before->accountedActiveSources, 'every active source is accounted for');
        assertTrue($after->reconciles(), 'all active sources are linked — reconciled');
    }

    public function testAnUnlinkedActiveSourceIsUnaccountedForReconciliation(): void
    {
        $before = $this->reader->summary();

        // A raw source that is mirrored but NOT linked to any canonical.
        $sourceId = self::SOURCE_LO + (++$this->seq);
        $this->rules->save($this->mirror($sourceId, 'ZZRPT Orphan', 'body'), 1, $this->now);

        $after = $this->reader->summary();

        assertSame(1, $after->sourceActive - $before->sourceActive);
        assertSame(0, $after->accountedActiveSources - $before->accountedActiveSources, 'an unlinked source is not accounted for');
        assertSame(1, $after->unaccountedActiveSources() - $before->unaccountedActiveSources());
    }

    public function testListFiltersBySearchAndClassification(): void
    {
        $this->classified('ZZRPT List Alpha', ClassificationStatus::AutoMatched, RuleScope::StoreSpecific);
        $this->classified('ZZRPT List Beta', ClassificationStatus::Pending, RuleScope::Unresolved);
        $this->classified('ZZRPT List Gamma', ClassificationStatus::ConfirmedCommon, RuleScope::Common);

        assertSame(3, $this->reader->list(new RuleReportQuery('ZZRPT List', RuleReportFilter::All, 1, 30))->total);
        assertSame(1, $this->reader->list(new RuleReportQuery('ZZRPT List', RuleReportFilter::AutoMatched, 1, 30))->total);
        assertSame(1, $this->reader->list(new RuleReportQuery('ZZRPT List', RuleReportFilter::Pending, 1, 30))->total);
        assertSame(1, $this->reader->list(new RuleReportQuery('ZZRPT List', RuleReportFilter::ConfirmedCommon, 1, 30))->total);
        // "Needs review" bundles pending / suggested_common / ambiguous / unmatched (here: just Beta).
        assertSame(1, $this->reader->list(new RuleReportQuery('ZZRPT List', RuleReportFilter::NeedsReview, 1, 30))->total);

        $one = $this->reader->list(new RuleReportQuery('ZZRPT List Alpha', RuleReportFilter::All, 1, 30));
        assertSame(1, $one->total);
        assertSame('ZZRPT List Alpha', $one->items[0]->title);
        assertSame('auto_matched', $one->items[0]->classificationStatus);
    }

    public function testListReportsDuplicateGroupSize(): void
    {
        // Two identical-content sources → one canonical with two linked sources.
        $this->linkedRecord('ZZRPT Dup', 'same body');
        $this->linkedRecord('ZZRPT Dup', 'same body');

        $result = $this->reader->list(new RuleReportQuery('ZZRPT Dup', RuleReportFilter::All, 1, 30));

        assertSame(1, $result->total);
        assertSame(2, $result->items[0]->duplicateGroupSize);
    }

    public function testFindDetailReturnsClassificationAndTheSuggestedStore(): void
    {
        $store = 976000090;
        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $store, 'name' => 'ZZRPT Detail Store', 'active' => 1, 'sync_hash' => 'h', 'synced_at' => $this->ts(),
            'created_at' => $this->ts(), 'updated_at' => $this->ts(),
        ])->execute();
        $canonical = $this->classified('ZZRPT Detail Rule', ClassificationStatus::AutoMatched, RuleScope::StoreSpecific);
        (new DbRuleStoreLinkRepository($this->connection))->upsertSystemLink(
            $canonical,
            $store,
            StoreMatchStatus::Suggested,
            StoreMatchMethod::TitleExactAlias,
            'ZZRPT Detail Store',
            0.9,
            $this->now,
        );

        $detail = $this->reader->findDetail($canonical);

        assertNotNull($detail);
        assertSame($canonical, $detail->canonicalId);
        assertSame('ZZRPT Detail Rule', $detail->title);
        assertSame('auto_matched', $detail->classificationStatus);
        assertSame($store, $detail->suggestedStoreId);
        assertSame('ZZRPT Detail Store', $detail->suggestedStoreName);
        assertSame(null, $detail->matchedStoreId, 'auto-match is only a suggestion, not confirmed');
        assertSame([], $detail->documents, 'not materialized until confirmed');
    }

    public function testFindDetailIsNullForAMissingRule(): void
    {
        assertSame(null, $this->reader->findDetail(0));
    }

    public function testGloballyAvailableFiltersReflectTheExplicitFlag(): void
    {
        $on = $this->classified('ZZRPT GA On', ClassificationStatus::ConfirmedCommon, RuleScope::Common);
        $off = $this->classified('ZZRPT GA Off', ClassificationStatus::ConfirmedCommon, RuleScope::Common);
        // Availability is a flag independent of classification — turn one off.
        $this->catalog->setGloballyAvailable($off, false, $this->now);

        $available = $this->reader->list(new RuleReportQuery('ZZRPT GA', RuleReportFilter::GloballyAvailable, 1, 30));
        $notAvailable = $this->reader->list(new RuleReportQuery('ZZRPT GA', RuleReportFilter::NotGloballyAvailable, 1, 30));

        assertSame(1, $available->total);
        assertSame('ZZRPT GA On', $available->items[0]->title);
        assertTrue($available->items[0]->isGloballyAvailable());
        assertSame(1, $notAvailable->total);
        assertSame('ZZRPT GA Off', $notAvailable->items[0]->title);
    }

    public function testConfirmedStoreRuleReportsBothProjectionStatusesAndIsGloballyAvailable(): void
    {
        $store = 976000091;
        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => $store, 'name' => 'ZZRPT GA Store', 'active' => 1, 'sync_hash' => 'h', 'synced_at' => $this->ts(),
            'created_at' => $this->ts(), 'updated_at' => $this->ts(),
        ])->execute();
        $this->kbSources->createForSource('ZZRPT GA Store', 'zzrpt-ga-store', 'order58', $store, 'ZZRPT GA Store', true, $this->now);

        // A confirmed store-specific rule → NO store projection (store chat answers only from store knowledge) but
        // a global projection (the Rule Chat corpus). Classification/scope are still preserved for reporting.
        $canonical = $this->classified('ZZRPT GA Rule', ClassificationStatus::ManuallyMatched, RuleScope::StoreSpecific);
        (new DbRuleStoreLinkRepository($this->connection))->setAdminLink($canonical, $store, StoreMatchStatus::Confirmed, 7, $this->now);
        $this->reconciler->reconcile($canonical, $this->now);

        // The report keeps store scope AND separately reports global availability + both document statuses.
        $result = $this->reader->list(new RuleReportQuery('ZZRPT GA Rule', RuleReportFilter::GloballyAvailable, 1, 30));
        assertSame(1, $result->total, 'the globally-available filter surfaces the confirmed store rule');
        $item = $result->items[0];
        assertSame('manually_matched', $item->classificationStatus, 'store classification is preserved for reporting');
        assertTrue($item->isGloballyAvailable());
        assertNull($item->storeDocumentStatus, 'store-rule documents are no longer projected into store KBs');
        assertSame('queued', $item->globalDocumentStatus);
    }

    private function classified(string $title, ClassificationStatus $status, RuleScope $scope): int
    {
        $identity = (new RuleHasher())->identify($title, $title);
        $id = $this->catalog->insertCanonical(
            substr($identity->canonicalHash, 0, 56) . str_pad((string) (++$this->seq), 8, '0', STR_PAD_LEFT),
            $identity->descriptionHash,
            $title,
            $identity->content,
            $this->now,
        );
        $this->catalog->updateClassification($id, $scope->value, $status->value, 'test', null, null, $this->now);

        return $id;
    }

    private function ts(): string
    {
        return $this->now->format('Y-m-d H:i:s');
    }

    private function linkedRecord(string $title, string $body): void
    {
        $sourceId = self::SOURCE_LO + (++$this->seq);
        $this->rules->save($this->mirror($sourceId, $title, $body), 1, $this->now);
        $recordId = (int) $this->rules->findIdBySourceId($sourceId);
        $this->catalogService->linkSource($recordId, $this->dto($sourceId, $title, $body), $this->now);
    }

    private function mirror(int $sourceId, string $title, string $body): RuleMirror
    {
        return new RuleMirror(null, $sourceId, 'Rule', $title, $body, null, 'admin2', null, true, 'h' . $sourceId, null, null, ['id' => $sourceId]);
    }

    private function dto(int $sourceId, string $title, string $body): Order58RuleRecord
    {
        return new Order58RuleRecord($sourceId, 'Rule', $title, $body, null, 'admin2', null, null, null, 'h' . $sourceId, ['id' => $sourceId]);
    }

    private function cleanup(): void
    {
        // Rule documents in the test store base and the hidden global base (must go before their bases).
        $this->connection->createCommand(
            'DELETE [[d]] FROM {{%documents}} [[d]] JOIN {{%knowledge_bases}} [[k]] ON [[k]].[[id]] = [[d]].[[knowledge_base_id]]'
            . ' WHERE [[k]].[[slug]] LIKE :s OR [[k]].[[slug]] = :common',
            [':s' => 'zzrpt%', ':common' => EnsureCommonRulesKnowledgeBaseService::SLUG],
        )->execute();
        // Store links reference canonical rules with RESTRICT — remove them (and events) first.
        $this->connection->createCommand(
            'DELETE [[l]] FROM {{%rule_store_links}} [[l]]'
            . ' JOIN {{%rule_catalog_rules}} [[k]] ON [[k]].[[id]] = [[l]].[[rule_catalog_rule_id]]'
            . ' WHERE [[k]].[[title]] LIKE :mark',
            [':mark' => self::TITLE_MARK . '%'],
        )->execute();
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
        $this->connection->createCommand(
            'DELETE FROM {{%knowledge_bases}} WHERE [[slug]] LIKE :s OR [[slug]] = :common',
            [':s' => 'zzrpt%', ':common' => EnsureCommonRulesKnowledgeBaseService::SLUG],
        )->execute();
        IntegrationDb::cleanup($this->connection, '{{%order58_stores}}', ['in', 'source_id', [976000090, 976000091]]);
    }
}
