<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Order58\Contract\Dto\Order58RuleRecord;
use App\Rules\Application\RuleCatalogOutcome;
use App\Rules\Application\RuleCatalogService;
use App\Rules\Application\RuleHasher;
use App\Tests\Support\Fake\ImmediateTransactionRunner;
use App\Tests\Support\Fake\Rules\InMemoryRuleCatalogRepository;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Links raw source rules to the canonical catalog, deduplicating by content identity. Same-title/different-body
 * rules stay separate; identical content collapses to one canonical with every source audit-linked; an upstream
 * content change re-links the same source to a new canonical (never a duplicate insert) and re-runs are no-ops.
 */
final class RuleCatalogServiceTest extends Unit
{
    private InMemoryRuleCatalogRepository $catalog;
    private RuleCatalogService $service;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->catalog = new InMemoryRuleCatalogRepository();
        $this->service = new RuleCatalogService($this->catalog, new RuleHasher(), new ImmediateTransactionRunner());
        $this->now = new DateTimeImmutable('2026-08-05 12:00:00', new DateTimeZone('UTC'));
    }

    public function testFirstSourceCreatesACanonicalAsPrimary(): void
    {
        $outcome = $this->link(101, 'Moon Temple', 'Call back on a dropped call.');

        assertSame(RuleCatalogOutcome::CanonicalCreated, $outcome);
        assertSame(1, $this->catalog->canonicalCount());
        assertSame('primary', $this->catalog->relationFor(101));
    }

    public function testIdenticalContentFromAnotherSourceIsAnExactDuplicateOfTheSameCanonical(): void
    {
        $this->link(101, 'Moon Temple', 'Shared body.');
        $outcome = $this->link(102, 'Moon Temple', 'Shared body.');

        assertSame(RuleCatalogOutcome::ExactDuplicateLinked, $outcome);
        assertSame(1, $this->catalog->canonicalCount(), 'one canonical rule');
        assertSame(2, $this->catalog->linkCount(), 'both raw sources are preserved and audit-linked');
        assertSame($this->catalog->canonicalIdFor(101), $this->catalog->canonicalIdFor(102));
        assertSame('exact_duplicate', $this->catalog->relationFor(102));
    }

    public function testSameTitleDifferentDescriptionsRemainSeparateCanonicals(): void
    {
        $this->link(101, 'Moon Temple', 'Body one.');
        $this->link(102, 'Moon Temple', 'A different body.');

        assertSame(2, $this->catalog->canonicalCount());
        assertFalse($this->catalog->canonicalIdFor(101) === $this->catalog->canonicalIdFor(102));
    }

    public function testWhitespaceOnlyEditIsNotTreatedAsAChange(): void
    {
        $this->link(101, 'Advance Order', "The body.\n");
        $outcome = $this->link(101, 'Advance Order', "The body.\r\n\r\n");

        assertSame(RuleCatalogOutcome::Unchanged, $outcome);
        assertSame(1, $this->catalog->canonicalCount());
        assertSame(1, $this->catalog->linkCount());
    }

    public function testReprocessingUnchangedContentIsIdempotent(): void
    {
        $this->link(101, 'Advance Order', 'Accept advance orders.');
        $outcome = $this->link(101, 'Advance Order', 'Accept advance orders.');

        assertSame(RuleCatalogOutcome::Unchanged, $outcome);
        assertSame(1, $this->catalog->canonicalCount());
        assertSame(1, $this->catalog->linkCount());
    }

    public function testUpstreamContentChangeRelinksTheSameSourceAndRetiresTheEmptyOldCanonical(): void
    {
        $this->link(101, 'Advance Order', 'Original body.');
        $original = $this->catalog->canonicalIdFor(101);

        $outcome = $this->link(101, 'Advance Order', 'A materially different body.');

        assertSame(RuleCatalogOutcome::Relinked, $outcome);
        assertSame(2, $this->catalog->canonicalCount(), 'a new canonical was created for the new content');
        assertSame(1, $this->catalog->linkCount(), 'still one link — the source moved, it did not duplicate');

        $moved = $this->catalog->canonicalIdFor(101);
        assertFalse($moved === $original, 'the source now points at the new canonical');
        // Both canonicals had their active flag recomputed; the old one has no sources left → inactive.
        assertContains($original, $this->catalog->recomputeCalls);
        assertContains($moved, $this->catalog->recomputeCalls);
        assertFalse($this->catalog->isActive((int) $original), 'old canonical goes inactive with no active sources');
        assertTrue($this->catalog->isActive((int) $moved));
    }

    public function testRelinkIsIdempotentOnReprocess(): void
    {
        $this->link(101, 'Advance Order', 'Original body.');
        $this->link(101, 'Advance Order', 'Changed body.');
        $outcome = $this->link(101, 'Advance Order', 'Changed body.');

        assertSame(RuleCatalogOutcome::Unchanged, $outcome);
        assertSame(2, $this->catalog->canonicalCount());
        assertSame(1, $this->catalog->linkCount());
    }

    private function link(int $recordId, string $title, string $description): RuleCatalogOutcome
    {
        return $this->service->linkSource($recordId, $this->record($recordId, $title, $description), $this->now);
    }

    private function record(int $sourceId, string $title, string $description): Order58RuleRecord
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
            syncHash: 'hash-' . $sourceId,
            raw: ['id' => $sourceId, 'title' => $title, 'description' => $description],
        );
    }
}
