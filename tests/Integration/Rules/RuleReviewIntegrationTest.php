<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules;

use App\Document\Domain\DocumentSourceType;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseRepository;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseSourceRepository;
use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\Rules\Application\RuleHasher;
use App\Rules\Application\RuleReviewService;
use App\Rules\Domain\ClassificationStatus;
use App\Rules\Domain\RuleScope;
use App\Rules\Infrastructure\DbRuleCatalogRepository;
use App\Rules\Infrastructure\DbRuleClassificationEventRepository;
use App\Rules\Infrastructure\DbRuleStoreLinkRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\RulesTestFactory;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function str_pad;
use function substr;
use function sys_get_temp_dir;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

use const STR_PAD_LEFT;

/**
 * Admin review decisions reconcile the searchable projection immediately (no full re-sync, no OpenAI in the
 * request — only queued document work): marking common materializes into the hidden base, confirming a store
 * materializes into the store base, and rejecting retires the projection and falls back to pending.
 */
final class RuleReviewIntegrationTest extends Unit
{
    private const TITLE_MARK = 'ZZREVIEW';
    private const STORE = 975000010;
    private const ADMIN = 42;

    private ConnectionInterface $connection;
    private DbRuleCatalogRepository $catalog;
    private DbRuleStoreLinkRepository $links;
    private DbKnowledgeBaseSourceRepository $kbSources;
    private DbKnowledgeBaseRepository $kbs;
    private RuleReviewService $review;
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
        $this->review = new RuleReviewService(
            $this->catalog,
            $this->links,
            new DbRuleClassificationEventRepository($this->connection),
            RulesTestFactory::reconciler($this->connection, sys_get_temp_dir() . '/kf_rules_review'),
            new SystemClock(),
        );
        $this->hasher = new RuleHasher();
        $this->now = new DateTimeImmutable('2026-08-05 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testMarkCommonImmediatelyMaterializesTheGlobalDocument(): void
    {
        $canonical = $this->seedPending('ZZREVIEW Callback', 'General callback');

        $this->review->markCommon($canonical, self::ADMIN);

        $globalKb = (new EnsureCommonRulesKnowledgeBaseService($this->kbs))->find();
        assertSame(ClassificationStatus::ConfirmedCommon->value, $this->statusOf($canonical));
        assertSame('queued', $this->docStatus($globalKb?->id() ?? 0, DocumentSourceType::Order58RuleGlobal, (string) $canonical));
    }

    public function testConfirmStoreMaterializesOnlyTheGlobalDocumentNotAStoreDocument(): void
    {
        $storeKb = $this->kbSources->createForSource('ZZREVIEW Store', 'zzreview-store', 'order58', self::STORE, 'ZZREVIEW Store', true, $this->now);
        $canonical = $this->seedPending('ZZREVIEW Advance', 'Accept advance orders');

        $this->review->confirmStore($canonical, self::STORE, self::ADMIN);

        $globalKb = (new EnsureCommonRulesKnowledgeBaseService($this->kbs))->find();
        assertSame(ClassificationStatus::ManuallyMatched->value, $this->statusOf($canonical));
        // Store chat answers only from genuine store knowledge: confirming a store no longer projects a store-rule
        // document into the store KB. The rule is globally available for Rule Chat.
        assertNull($this->docStatus($storeKb, DocumentSourceType::Order58RuleStore, (string) $canonical), 'no store-rule document is created for the store');
        assertSame('queued', $this->docStatus($globalKb?->id() ?? 0, DocumentSourceType::Order58RuleGlobal, (string) $canonical), 'the confirmed store rule is globally available');
    }

    public function testRejectingAStoreFallsBackToPendingAndPreservesTheGlobalProjection(): void
    {
        $storeKb = $this->kbSources->createForSource('ZZREVIEW Store', 'zzreview-store', 'order58', self::STORE, 'ZZREVIEW Store', true, $this->now);
        $canonical = $this->seedPending('ZZREVIEW Reject', 'Accept advance orders');
        $this->review->confirmStore($canonical, self::STORE, self::ADMIN);
        assertNull($this->docStatus($storeKb, DocumentSourceType::Order58RuleStore, (string) $canonical), 'no store-rule document is ever created');

        $this->review->rejectStore($canonical, self::STORE, self::ADMIN);

        assertSame(ClassificationStatus::Pending->value, $this->statusOf($canonical), 'falls back to pending, never silently common');
        assertNull($this->docStatus($storeKb, DocumentSourceType::Order58RuleStore, (string) $canonical), 'still no store-rule document');
        assertSame('queued', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical), 'rejecting a store preserves the global projection');
    }

    public function testIgnoreRetiresTheGlobalProjection(): void
    {
        $storeKb = $this->kbSources->createForSource('ZZREVIEW Store', 'zzreview-store', 'order58', self::STORE, 'ZZREVIEW Store', true, $this->now);
        $canonical = $this->seedPending('ZZREVIEW Ignore', 'Accept advance orders');
        $this->review->confirmStore($canonical, self::STORE, self::ADMIN);
        assertNull($this->docStatus($storeKb, DocumentSourceType::Order58RuleStore, (string) $canonical), 'no store-rule document is created');
        assertSame('queued', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical));

        $this->review->ignore($canonical, self::ADMIN);

        assertSame('deleted', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical), 'an ignored rule is not globally available');
    }

    public function testDisableThenEnableGlobalAvailabilityTogglesTheGlobalProjection(): void
    {
        $canonical = $this->seedPending('ZZREVIEW Toggle', 'General callback');
        $this->review->markCommon($canonical, self::ADMIN);
        assertSame('queued', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical));

        $this->review->setGloballyAvailable($canonical, false, self::ADMIN);
        assertSame('deleted', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical), 'disabling retires the global projection');

        // Re-enabling requeues the same projection under the replacement guard (no duplicate).
        $this->review->setGloballyAvailable($canonical, true, self::ADMIN);
        assertSame('queued', $this->docStatus($this->globalKbId(), DocumentSourceType::Order58RuleGlobal, (string) $canonical), 're-enabling restores the global projection');
    }

    private function globalKbId(): int
    {
        return (new EnsureCommonRulesKnowledgeBaseService($this->kbs))->find()?->id() ?? 0;
    }

    private function seedPending(string $title, string $body): int
    {
        $identity = $this->hasher->identify($title, $body);
        $id = $this->catalog->insertCanonical(
            substr($identity->canonicalHash, 0, 56) . str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            $identity->descriptionHash,
            $title,
            $identity->content,
            $this->now,
        );
        $this->catalog->updateClassification($id, RuleScope::Unresolved->value, ClassificationStatus::Pending->value, 'seed', null, null, $this->now);

        return $id;
    }

    private function statusOf(int $canonicalId): string
    {
        return (string) $this->catalog->findClassification($canonicalId)['classification_status'];
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

    private function cleanup(): void
    {
        $this->connection->createCommand(
            'DELETE [[d]] FROM {{%documents}} [[d]] JOIN {{%knowledge_bases}} [[k]] ON [[k]].[[id]] = [[d]].[[knowledge_base_id]]'
            . ' WHERE [[k]].[[slug]] LIKE :s OR [[k]].[[slug]] = :common',
            [':s' => 'zzreview%', ':common' => EnsureCommonRulesKnowledgeBaseService::SLUG],
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
            [':s' => 'zzreview%', ':common' => EnsureCommonRulesKnowledgeBaseService::SLUG],
        )->execute();
    }
}
