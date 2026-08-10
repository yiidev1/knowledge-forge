<?php

declare(strict_types=1);

namespace App\Order58\Application\Sync;

use App\Order58\Application\Mapper\RuleMapper;
use App\Order58\Application\Order58SyncParams;
use App\Order58\Contract\Dto\Order58RuleRecord;
use App\Order58\Contract\Exception\Order58Exception;
use App\Order58\Contract\Order58ClientInterface;
use App\Order58\Domain\Order58RuleRepositoryInterface;
use App\Order58\Domain\SyncProgress;
use App\Order58\Domain\SyncRun;
use App\Order58\Domain\SyncRunRepositoryInterface;
use App\Rules\Application\RuleCatalogOutcome;
use App\Rules\Application\RuleCatalogService;
use App\Rules\Application\RuleClassificationRunner;
use App\Rules\Application\RuleReconciliationRunner;
use App\Rules\Domain\ClassificationContext;
use DateTimeImmutable;

/**
 * Full paginated scan of the Order58 Rules API. Mirrors each rule into `order58_rule_records` and links it to a
 * canonical rule (exact-content dedupe). After a successful full scan, automatically reconciles global rule
 * projections (hidden shared-rules KB + `order58_rule_global` documents) through the existing local queue —
 * never calling OpenAI from this path.
 *
 * The scan is gentle on the upstream server: it uses the paginated list only (never `GET /rules/{id}`), pages
 * `per_page` at a time and follows the response's `total_pages`, yields after `pagesPerRun` pages so a large
 * catalog drains across worker passes, and relies on the client's capped backoff (which honors `Retry-After`).
 *
 * Sync semantics:
 *  - new      → INSERT (save) + catalog link + classify/reconcile
 *  - changed  → UPDATE (save) + catalog relink as needed + classify/reconcile
 *  - unchanged → skip content rewrite / reclassify / unnecessary reindex; still reconcile `is_active` from
 *    upstream truth (explicit `active` when present, else presence = active)
 *
 * Mark-and-sweep runs only after the final page succeeds: unseen active records are soft-deactivated and their
 * canonical rules' active flags recomputed. An interrupted (partial) run never sweeps, so it never wrongly
 * deactivates a record the source still returns.
 */
final readonly class RulesSyncHandler implements Order58SyncHandlerInterface
{
    public function __construct(
        private Order58ClientInterface $client,
        private Order58RuleRepositoryInterface $rules,
        private RuleMapper $mapper,
        private RuleCatalogService $catalog,
        private RuleClassificationRunner $classifier,
        private RuleReconciliationRunner $projections,
        private SyncRunRepositoryInterface $runs,
        private Order58SyncParams $params,
    ) {}

    public function handle(SyncRun $run, DateTimeImmutable $now): SyncOutcome
    {
        $progress = $run->progress();
        $runId = $run->id();
        $pagesThisRun = 0;

        // Seed aliases from the current stores and build the shared classification context once per run.
        $context = $this->classifier->prepare($now);

        while (true) {
            try {
                $page = $this->client->listRules($progress->nextPage, $this->params->pageSize);
            } catch (Order58Exception $e) {
                return $e->isTransient()
                    ? SyncOutcome::transient($progress, $e->details()->code, $e->getMessage())
                    : SyncOutcome::failed($progress, $e->details()->code, $e->getMessage());
            }

            foreach ($page->items as $record) {
                $this->processRecord($record, $runId, $progress, $context, $now);
            }

            $progress->pagesProcessed++;
            $progress->nextPage++;
            $pagesThisRun++;
            $this->runs->saveProgress($runId, $progress, $now);

            if (!$page->pagination->hasMore()) {
                break;
            }
            if ($pagesThisRun >= $this->params->pagesPerRun) {
                return SyncOutcome::partial($progress);
            }
        }

        // Mark-and-sweep (final page only): soft-deactivate unseen records, recompute canonical active flags,
        // and retire the searchable projection of any canonical that just went inactive (both store + global).
        foreach ($this->rules->deactivateNotSeen($runId, $now) as $deactivated) {
            $this->catalog->recomputeActiveForRecord($deactivated['record_id'], $now);
            $this->classifier->reconcileForRecord($deactivated['record_id'], $now);
            $progress->deactivated++;
        }

        // Successful full scan: ensure every active/global-eligible canonical has a queued projection.
        // Idempotent — unchanged global documents are not requeued; the worker owns OpenAI work.
        $this->projections->reconcileAllActive($now);

        return SyncOutcome::completed($progress);
    }

    private function processRecord(Order58RuleRecord $record, int $runId, SyncProgress $progress, ClassificationContext $context, DateTimeImmutable $now): void
    {
        $existingHash = $this->rules->findSyncHash($record->id);
        $hashUnchanged = $existingHash === $record->syncHash;
        $desiredActive = RuleMapper::resolveActive($record);

        if ($hashUnchanged) {
            // Content skip path: no content rewrite, no reclassify, no forced reindex — but lifecycle state
            // must still follow upstream truth (fixes stale is_active=0 while the rule is still present).
            $activityChanged = $this->rules->markSeen($record->id, $runId, $now, $desiredActive);
            $recordId = $this->rules->findIdBySourceId($record->id);
            if ($recordId === null) {
                $progress->warnings++;
                $progress->unchanged++;

                return;
            }

            if ($activityChanged) {
                $this->catalog->recomputeActiveForRecord($recordId, $now);
                // Projection create/retire for the activity flip; end-of-scan reconcileAllActive heals the rest.
                $this->classifier->reconcileForRecord($recordId, $now);
            }

            $progress->unchanged++;

            return;
        }

        $this->rules->save($this->mapper->toMirror($record), $runId, $now);

        $recordId = $this->rules->findIdBySourceId($record->id);
        if ($recordId === null) {
            // Should never happen (we just saved/marked it), but never let a missing id abort the scan.
            $progress->warnings++;

            return;
        }

        $outcome = $this->catalog->linkSource($recordId, $record, $now);

        // Deterministic store matching + classification (idempotent; never overwrites an admin decision).
        $this->classifier->classifyForRecord($recordId, $context, $now);

        if ($existingHash === null) {
            $progress->created++;
        } else {
            $progress->updated++;
        }

        match ($outcome) {
            RuleCatalogOutcome::CanonicalCreated => $progress->canonicalCreated++,
            RuleCatalogOutcome::ExactDuplicateLinked => $progress->exactDuplicates++,
            RuleCatalogOutcome::Relinked, RuleCatalogOutcome::Unchanged => null,
        };
    }
}
