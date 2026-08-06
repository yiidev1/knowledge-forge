<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules;

use App\Order58\Contract\Dto\Order58RuleRecord;
use App\Order58\Domain\RuleMirror;
use App\Order58\Infrastructure\DbOrder58RuleRepository;
use App\Rules\Application\RuleCatalogOutcome;
use App\Rules\Application\RuleCatalogService;
use App\Rules\Application\RuleHasher;
use App\Rules\Infrastructure\DbRuleCatalogRepository;
use App\Shared\Application\Transaction\TransactionalRunner;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

/**
 * The canonical catalog against real MySQL: the `canonical_hash` UNIQUE dedupe, exact-duplicate linking, the
 * transactional re-link + cross-table `is_active` recompute on an upstream content change, the sweep recompute,
 * and the audit-preserving RESTRICT foreign keys. Sentinel ids/markers keep the shared dev database undisturbed.
 */
final class RuleCatalogIntegrationTest extends Unit
{
    private const TITLE_MARK = 'ZZRULECAT';
    private const SOURCE_LO = 971000001;
    private const SOURCE_HI = 971000099;

    private ConnectionInterface $connection;
    private DbOrder58RuleRepository $rules;
    private DbRuleCatalogRepository $catalog;
    private RuleCatalogService $service;
    private RuleHasher $hasher;
    private DateTimeImmutable $now;
    private int $seq = 0;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->rules = new DbOrder58RuleRepository($this->connection);
        $this->catalog = new DbRuleCatalogRepository($this->connection);
        $this->hasher = new RuleHasher();
        $this->service = new RuleCatalogService($this->catalog, $this->hasher, new TransactionalRunner($this->connection));
        $this->now = new DateTimeImmutable('2026-08-05 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testIdenticalContentDeduplicatesToOneCanonicalWithBothSourcesLinked(): void
    {
        $a = $this->seedRecord(self::TITLE_MARK . ' Moon Temple', 'Shared body.');
        $b = $this->seedRecord(self::TITLE_MARK . ' Moon Temple', 'Shared body.');

        $first = $this->service->linkSource($a, $this->dto($a, self::TITLE_MARK . ' Moon Temple', 'Shared body.'), $this->now);
        $second = $this->service->linkSource($b, $this->dto($b, self::TITLE_MARK . ' Moon Temple', 'Shared body.'), $this->now);

        assertSame(RuleCatalogOutcome::CanonicalCreated, $first);
        assertSame(RuleCatalogOutcome::ExactDuplicateLinked, $second);
        assertSame($this->catalog->findCanonicalIdForRecord($a), $this->catalog->findCanonicalIdForRecord($b));
        assertSame('exact_duplicate', $this->relationOf($b));
    }

    public function testSameTitleDifferentDescriptionsProduceSeparateCanonicals(): void
    {
        $a = $this->seedRecord(self::TITLE_MARK . ' Moon Temple', 'Body one.');
        $b = $this->seedRecord(self::TITLE_MARK . ' Moon Temple', 'Body two.');

        $this->service->linkSource($a, $this->dto($a, self::TITLE_MARK . ' Moon Temple', 'Body one.'), $this->now);
        $this->service->linkSource($b, $this->dto($b, self::TITLE_MARK . ' Moon Temple', 'Body two.'), $this->now);

        assertFalse($this->catalog->findCanonicalIdForRecord($a) === $this->catalog->findCanonicalIdForRecord($b));
    }

    public function testUpstreamContentChangeRelinksAndRecomputesActiveOnBothCanonicals(): void
    {
        $r = $this->seedRecord(self::TITLE_MARK . ' Advance', 'Original body.');
        $this->service->linkSource($r, $this->dto($r, self::TITLE_MARK . ' Advance', 'Original body.'), $this->now);
        $oldCanonical = (int) $this->catalog->findCanonicalIdForRecord($r);

        $outcome = $this->service->linkSource($r, $this->dto($r, self::TITLE_MARK . ' Advance', 'A different body.'), $this->now);
        $newCanonical = (int) $this->catalog->findCanonicalIdForRecord($r);

        assertSame(RuleCatalogOutcome::Relinked, $outcome);
        assertFalse($oldCanonical === $newCanonical);
        // Cross-table recompute: the old canonical lost its only source → inactive; the new one is active.
        assertSame(0, $this->activeOfCanonical($oldCanonical), 'old canonical goes inactive with no active sources');
        assertSame(1, $this->activeOfCanonical($newCanonical));
    }

    public function testSweepDeactivatingASourceRetiresItsCanonical(): void
    {
        $r = $this->seedRecord(self::TITLE_MARK . ' Callback', 'Call back on a dropped call.');
        $this->service->linkSource($r, $this->dto($r, self::TITLE_MARK . ' Callback', 'Call back on a dropped call.'), $this->now);
        $canonical = (int) $this->catalog->findCanonicalIdForRecord($r);
        assertSame(1, $this->activeOfCanonical($canonical));

        // Simulate a mark-and-sweep deactivating the raw record, then recompute its canonical.
        $this->connection->createCommand()->update('{{%order58_rule_records}}', ['is_active' => 0], ['id' => $r])->execute();
        $this->service->recomputeActiveForRecord($r, $this->now);

        assertSame(0, $this->activeOfCanonical($canonical), 'a canonical with no active sources becomes inactive');
    }

    public function testForeignKeysRestrictDeletingLinkedAuditRows(): void
    {
        $r = $this->seedRecord(self::TITLE_MARK . ' Restrict', 'A body.');
        $this->service->linkSource($r, $this->dto($r, self::TITLE_MARK . ' Restrict', 'A body.'), $this->now);
        $canonical = (int) $this->catalog->findCanonicalIdForRecord($r);

        assertInstanceOf(
            IntegrityException::class,
            $this->deleteAndCatch('{{%order58_rule_records}}', ['id' => $r]),
            'a linked raw source rule must not be deletable (RESTRICT)',
        );
        assertInstanceOf(
            IntegrityException::class,
            $this->deleteAndCatch('{{%rule_catalog_rules}}', ['id' => $canonical]),
            'a canonical rule with a source link must not be deletable (RESTRICT)',
        );
    }

    private function deleteAndCatch(string $table, array $condition): ?Throwable
    {
        try {
            $this->connection->createCommand()->delete($table, $condition)->execute();
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }

    private function seedRecord(string $title, string $description): int
    {
        $sourceId = self::SOURCE_LO + $this->seq++;
        $this->rules->save(
            new RuleMirror(
                id: null,
                sourceId: $sourceId,
                type: 'Rule',
                title: $title,
                description: $description,
                ruleKeyword: null,
                createdName: 'admin2',
                sourceStoreId: null,
                active: true,
                syncHash: 'h' . $sourceId,
                sourceCreatedAt: null,
                sourceUpdatedAt: null,
                snapshot: ['id' => $sourceId],
            ),
            1,
            $this->now,
        );
        $id = $this->rules->findIdBySourceId($sourceId);
        assertNotNull($id);

        return $id;
    }

    private function dto(int $recordId, string $title, string $description): Order58RuleRecord
    {
        return new Order58RuleRecord(
            id: $recordId,
            type: 'Rule',
            title: $title,
            description: $description,
            ruleKeyword: null,
            createdName: 'admin2',
            sourceStoreId: null,
            createdAt: null,
            updatedAt: null,
            syncHash: 'h' . $recordId,
            raw: ['id' => $recordId],
        );
    }

    private function relationOf(int $recordId): ?string
    {
        $link = $this->catalog->findSourceLink($recordId);

        return $link['relation_type'] ?? null;
    }

    private function activeOfCanonical(int $canonicalId): int
    {
        return (int) $this->connection
            ->createQuery()
            ->select('is_active')
            ->from('{{%rule_catalog_rules}}')
            ->where(['id' => $canonicalId])
            ->scalar();
    }

    private function cleanup(): void
    {
        // FK-safe order (RESTRICT): source links first, then canonical rules, then raw records.
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
    }
}
