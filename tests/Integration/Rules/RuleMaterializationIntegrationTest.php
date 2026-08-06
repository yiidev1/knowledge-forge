<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules;

use App\Document\Domain\DocumentSourceType;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseRepository;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseSourceRepository;
use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\Rules\Application\RuleHasher;
use App\Rules\Application\RuleProjectionReconciler;
use App\Rules\Application\RuleReconciliationRunner;
use App\Rules\Domain\ClassificationStatus;
use App\Rules\Domain\RuleScope;
use App\Rules\Domain\StoreMatchMethod;
use App\Rules\Domain\StoreMatchStatus;
use App\Rules\Infrastructure\DbRuleCatalogRepository;
use App\Rules\Infrastructure\DbRuleStoreLinkRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\RulesTestFactory;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_map;
use function str_pad;
use function substr;
use function sys_get_temp_dir;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use const STR_PAD_LEFT;

/**
 * Materialization against real MySQL. Classification is separate from retrieval availability: EVERY active,
 * globally-available rule (any classification — store-specific, common, ambiguous, unmatched or pending) gets a
 * global projection in the hidden Global Rules base, and a store-specific rule with a confirmed store link ALSO
 * gets a store projection in its store KB. Repeats create no duplicates; ignoring / disabling global
 * availability / deactivating retires projections; changing the matched store moves the store projection while
 * preserving the global one.
 */
final class RuleMaterializationIntegrationTest extends Unit
{
    private const TITLE_MARK = 'ZZMAT';
    private const STORE = 974000010;
    private const STORE_SLUG = 'zzmat-store-1';
    private const STORE_B = 974000020;
    private const STORE_B_SLUG = 'zzmat-store-2';

    private ConnectionInterface $connection;
    private DbRuleCatalogRepository $catalog;
    private DbRuleStoreLinkRepository $links;
    private DbKnowledgeBaseSourceRepository $kbSources;
    private DbKnowledgeBaseRepository $kbs;
    private RuleProjectionReconciler $reconciler;
    private RuleHasher $hasher;
    private DateTimeImmutable $now;
    private int $seq = 0;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->catalog = new DbRuleCatalogRepository($this->connection);
        $this->links = new DbRuleStoreLinkRepository($this->connection);
        $this->kbSources = new DbKnowledgeBaseSourceRepository($this->connection);
        $this->kbs = new DbKnowledgeBaseRepository($this->connection, new SystemClock());
        $this->reconciler = RulesTestFactory::reconciler($this->connection, sys_get_temp_dir() . '/kf_rules_mat');
        $this->hasher = new RuleHasher();
        $this->now = new DateTimeImmutable('2026-08-05 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testConfirmedStoreRuleMaterializesBothProjectionsAndIsIdempotent(): void
    {
        $storeKb = $this->kbSources->createForSource('ZZMAT Store', self::STORE_SLUG, 'order58', self::STORE, 'ZZMAT Store', true, $this->now);
        $canonical = $this->seedCanonical('ZZMAT Advance orders', 'Accept advance orders.', ClassificationStatus::ManuallyMatched, RuleScope::StoreSpecific);
        $this->links->upsertSystemLink($canonical, self::STORE, StoreMatchStatus::Confirmed, StoreMatchMethod::TitleExactAlias, 'ZZMAT Store', 0.9, $this->now);

        $this->reconciler->reconcile($canonical, $this->now);

        // Store projection (stage-1 for its store) AND a global projection (stage-2 fallback for every store).
        assertSame('queued', $this->docStatus($storeKb, DocumentSourceType::Order58RuleStore, (string) $canonical));
        assertSame('queued', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical), 'a confirmed store rule is also globally available');

        // A second reconcile of unchanged data creates no duplicate document (either projection).
        $this->reconciler->reconcile($canonical, $this->now);
        assertSame(1, $this->docCount($storeKb, DocumentSourceType::Order58RuleStore, (string) $canonical));
        assertSame(1, $this->docCount($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical));
    }

    public function testChangingTheMatchedStoreMovesTheStoreProjectionButPreservesTheGlobal(): void
    {
        $storeA = $this->kbSources->createForSource('ZZMAT Store', self::STORE_SLUG, 'order58', self::STORE, 'ZZMAT Store', true, $this->now);
        $storeB = $this->kbSources->createForSource('ZZMAT Store B', self::STORE_B_SLUG, 'order58', self::STORE_B, 'ZZMAT Store B', true, $this->now);
        $canonical = $this->seedCanonical('ZZMAT Move', 'Accept advance orders.', ClassificationStatus::ManuallyMatched, RuleScope::StoreSpecific);
        $this->links->setAdminLink($canonical, self::STORE, StoreMatchStatus::Confirmed, 7, $this->now);
        $this->reconciler->reconcile($canonical, $this->now);
        assertSame('queued', $this->docStatus($storeA, DocumentSourceType::Order58RuleStore, (string) $canonical));

        // Admin changes the matched store: reject A, confirm B.
        $this->links->setAdminLink($canonical, self::STORE, StoreMatchStatus::Rejected, 7, $this->now);
        $this->links->setAdminLink($canonical, self::STORE_B, StoreMatchStatus::Confirmed, 7, $this->now);
        $this->reconciler->reconcile($canonical, $this->now);

        assertSame('deleted', $this->docStatus($storeA, DocumentSourceType::Order58RuleStore, (string) $canonical), 'the old store projection is retired');
        assertSame('queued', $this->docStatus($storeB, DocumentSourceType::Order58RuleStore, (string) $canonical), 'the new store projection is created');
        assertSame('queued', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical), 'the global projection is preserved across the move');
    }

    public function testUpstreamDeactivationRetiresBothProjections(): void
    {
        $storeKb = $this->kbSources->createForSource('ZZMAT Store', self::STORE_SLUG, 'order58', self::STORE, 'ZZMAT Store', true, $this->now);
        $canonical = $this->seedCanonical('ZZMAT Gone', 'Accept advance orders.', ClassificationStatus::ManuallyMatched, RuleScope::StoreSpecific);
        $this->links->upsertSystemLink($canonical, self::STORE, StoreMatchStatus::Confirmed, StoreMatchMethod::TitleExactAlias, 'ZZMAT Store', 0.9, $this->now);
        $this->reconciler->reconcile($canonical, $this->now);
        assertSame('queued', $this->docStatus($storeKb, DocumentSourceType::Order58RuleStore, (string) $canonical));
        assertSame('queued', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical));

        // The upstream source is removed → the canonical goes inactive. Reconciling retires everything.
        $this->connection->createCommand()->update('{{%rule_catalog_rules}}', ['is_active' => 0], ['id' => $canonical])->execute();
        $this->reconciler->reconcile($canonical, $this->now);

        assertSame('deleted', $this->docStatus($storeKb, DocumentSourceType::Order58RuleStore, (string) $canonical));
        assertSame('deleted', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical), 'an inactive rule is not globally available');
    }

    public function testAutoMatchedRuleMaterializesBothProjectionsWithoutManualConfirmation(): void
    {
        $storeKb = $this->kbSources->createForSource('ZZMAT Store', self::STORE_SLUG, 'order58', self::STORE, 'ZZMAT Store', true, $this->now);
        $canonical = $this->seedCanonical('ZZMAT Auto', 'Accept advance orders.', ClassificationStatus::AutoMatched, RuleScope::StoreSpecific);
        // A deterministic single match is a CONFIRMED system link — searchable without any admin step.
        $this->links->upsertSystemLink($canonical, self::STORE, StoreMatchStatus::Confirmed, StoreMatchMethod::TitleExactAlias, 'ZZMAT Store', 0.9, $this->now);

        $this->reconciler->reconcile($canonical, $this->now);

        assertSame('queued', $this->docStatus($storeKb, DocumentSourceType::Order58RuleStore, (string) $canonical), 'the matched store gets the rule at stage 1');
        assertSame('queued', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical), 'and it is globally available at stage 2');
    }

    public function testPendingRuleGetsAGlobalProjectionButNoStoreProjection(): void
    {
        $storeKb = $this->kbSources->createForSource('ZZMAT Store', self::STORE_SLUG, 'order58', self::STORE, 'ZZMAT Store', true, $this->now);
        $canonical = $this->seedCanonical('ZZMAT Pending', 'Some rule', ClassificationStatus::Pending, RuleScope::Unresolved);

        $this->reconciler->reconcile($canonical, $this->now);

        assertNull($this->docStatus($storeKb, DocumentSourceType::Order58RuleStore, (string) $canonical), 'no confirmed store → no store projection');
        assertSame('queued', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical), 'a pending rule is still globally available by default');
    }

    public function testAmbiguousAndUnmatchedRulesGetGlobalProjectionButNoStoreProjection(): void
    {
        $storeKb = $this->kbSources->createForSource('ZZMAT Store', self::STORE_SLUG, 'order58', self::STORE, 'ZZMAT Store', true, $this->now);
        // Ambiguous: two suggested (never confirmed) store links → no store projection.
        $ambiguous = $this->seedCanonical('ZZMAT Ambiguous', 'Applies to two stores.', ClassificationStatus::Ambiguous, RuleScope::Unresolved);
        $this->links->upsertSystemLink($ambiguous, self::STORE, StoreMatchStatus::Suggested, StoreMatchMethod::TitleExactAlias, 'ZZMAT Store', 0.9, $this->now);
        $this->links->upsertSystemLink($ambiguous, self::STORE_B, StoreMatchStatus::Suggested, StoreMatchMethod::TitleExactAlias, 'ZZMAT Store B', 0.9, $this->now);
        // Unmatched: an apparent store not in the mirror → no link at all.
        $unmatched = $this->seedCanonical('ZZMAT Unmatched', 'For a store we do not have.', ClassificationStatus::Unmatched, RuleScope::Unresolved);

        $this->reconciler->reconcile($ambiguous, $this->now);
        $this->reconciler->reconcile($unmatched, $this->now);

        assertNull($this->docStatus($storeKb, DocumentSourceType::Order58RuleStore, (string) $ambiguous), 'never silently pick one store for an ambiguous rule');
        assertSame('queued', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $ambiguous));
        assertSame('queued', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $unmatched));
    }

    public function testReconciliationRunnerBackfillsGlobalProjectionsIdempotently(): void
    {
        $pending = $this->seedCanonical('ZZMAT Backfill', 'Some store-agnostic rule', ClassificationStatus::Pending, RuleScope::Unresolved);
        $runner = new RuleReconciliationRunner($this->catalog, $this->reconciler, new SystemClock());

        $count = $runner->reconcileAllActive();

        assertTrue($count >= 1, 'the backfill reconciled at least the seeded active rule');
        assertSame('queued', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $pending));

        // A second backfill creates no duplicate global document.
        $runner->reconcileAllActive();
        assertSame(1, $this->docCount($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $pending));
    }

    public function testDisablingGlobalAvailabilityRetiresTheGlobalProjection(): void
    {
        $canonical = $this->seedCanonical('ZZMAT Disable', 'General callback for all stores', ClassificationStatus::ConfirmedCommon, RuleScope::Common);
        $this->reconciler->reconcile($canonical, $this->now);
        assertSame('queued', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical));

        // An admin disables global availability (the classification is unchanged).
        $this->catalog->setGloballyAvailable($canonical, false, $this->now);
        $this->reconciler->reconcile($canonical, $this->now);

        assertSame('deleted', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical), 'disabling global availability retires the global projection');
    }

    public function testConfirmedCommonMaterializesIntoAHiddenBaseExcludedFromTheAdminDirectory(): void
    {
        $canonical = $this->seedCanonical('ZZMAT Common', 'General callback for all stores', ClassificationStatus::ConfirmedCommon, RuleScope::Common);

        $this->reconciler->reconcile($canonical, $this->now);

        $commonKb = (new EnsureCommonRulesKnowledgeBaseService($this->kbs))->find();
        assertNotNull($commonKb, 'the hidden Global Rules base was created lazily');
        assertSame('queued', $this->docStatus($commonKb->id(), DocumentSourceType::Order58RuleGlobal, (string) $canonical));

        // Hidden from the admin KB directory, but visible to internal callers (default findAll).
        $adminIds = array_map(static fn($kb): int => $kb->id(), $this->kbs->findAll(excludeSystem: true));
        $internalIds = array_map(static fn($kb): int => $kb->id(), $this->kbs->findAll(includeArchived: true));
        assertNotContains($commonKb->id(), $adminIds, 'hidden base is excluded from the admin directory');
        assertContains($commonKb->id(), $internalIds, 'hidden base remains visible to internal callers');
    }

    public function testReclassifyingToIgnoredRetiresBothProjections(): void
    {
        $storeKb = $this->kbSources->createForSource('ZZMAT Store', self::STORE_SLUG, 'order58', self::STORE, 'ZZMAT Store', true, $this->now);
        $canonical = $this->seedCanonical('ZZMAT Retire', 'Accept advance orders.', ClassificationStatus::ManuallyMatched, RuleScope::StoreSpecific);
        $this->links->upsertSystemLink($canonical, self::STORE, StoreMatchStatus::Confirmed, StoreMatchMethod::TitleExactAlias, 'ZZMAT', 0.9, $this->now);
        $this->reconciler->reconcile($canonical, $this->now);
        assertSame('queued', $this->docStatus($storeKb, DocumentSourceType::Order58RuleStore, (string) $canonical));
        assertSame('queued', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical));

        // An admin ignores the rule (status ignored + global availability off); both projections retire.
        $this->catalog->updateClassification($canonical, RuleScope::Unresolved->value, ClassificationStatus::Ignored->value, 'ignored', null, 7, $this->now);
        $this->catalog->setGloballyAvailable($canonical, false, $this->now);
        $this->reconciler->reconcile($canonical, $this->now);

        assertSame('deleted', $this->docStatus($storeKb, DocumentSourceType::Order58RuleStore, (string) $canonical), 'the store projection is retired');
        assertSame('deleted', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical), 'an ignored rule is no longer globally available');
    }

    private function seedCanonical(string $title, string $body, ClassificationStatus $status, RuleScope $scope): int
    {
        $identity = $this->hasher->identify($title, $body);
        $id = $this->catalog->insertCanonical(
            substr($identity->canonicalHash, 0, 56) . str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            $identity->descriptionHash,
            $title,
            $identity->content,
            $this->now,
        );
        $this->catalog->updateClassification($id, $scope->value, $status->value, 'test', null, null, $this->now);

        return $id;
    }

    private function docStatus(int $kbId, DocumentSourceType $type, string $ref): ?string
    {
        $value = $this->connection->createQuery()
            ->select('status')
            ->from('{{%documents}}')
            ->where(['knowledge_base_id' => $kbId, 'source_type' => $type->value, 'source_ref' => $ref])
            ->scalar();

        return $value === false || $value === null ? null : (string) $value;
    }

    private function globalKbId(): int
    {
        return (new EnsureCommonRulesKnowledgeBaseService($this->kbs))->find()?->id() ?? 0;
    }

    private function docCount(int $kbId, DocumentSourceType $type, string $ref): int
    {
        return (int) $this->connection->createQuery()
            ->from('{{%documents}}')
            ->where(['knowledge_base_id' => $kbId, 'source_type' => $type->value, 'source_ref' => $ref])
            ->count();
    }

    private function cleanup(): void
    {
        // Documents in our test store base + the hidden common base.
        $this->connection->createCommand(
            'DELETE [[d]] FROM {{%documents}} [[d]] JOIN {{%knowledge_bases}} [[k]] ON [[k]].[[id]] = [[d]].[[knowledge_base_id]]'
            . " WHERE [[k]].[[slug]] LIKE :s OR [[k]].[[slug]] = :common",
            [':s' => 'zzmat%', ':common' => EnsureCommonRulesKnowledgeBaseService::SLUG],
        )->execute();
        foreach (['{{%rule_store_links}}', '{{%rule_classification_events}}'] as $child) {
            $this->connection->createCommand(
                'DELETE [[c]] FROM ' . $child . ' [[c]] JOIN {{%rule_catalog_rules}} [[r]] ON [[r]].[[id]] = [[c]].[[rule_catalog_rule_id]]'
                . ' WHERE [[r]].[[title]] LIKE :mark',
                [':mark' => self::TITLE_MARK . '%'],
            )->execute();
        }
        IntegrationDb::cleanup($this->connection, '{{%rule_catalog_rules}}', ['like', 'title', self::TITLE_MARK]);
        $this->connection->createCommand(
            'DELETE FROM {{%knowledge_bases}} WHERE [[slug]] LIKE :s OR [[slug]] = :common',
            [':s' => 'zzmat%', ':common' => EnsureCommonRulesKnowledgeBaseService::SLUG],
        )->execute();
    }
}
