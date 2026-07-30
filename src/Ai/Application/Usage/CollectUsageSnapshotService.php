<?php

declare(strict_types=1);

namespace App\Ai\Application\Usage;

use App\Ai\Contract\Exception\AiException;
use App\Ai\OpenAi\Client\OpenAiClientInterface;
use App\Ai\OpenAi\Dto\OpenAiVectorStore;
use App\Shared\Domain\Clock\ClockInterface;

/**
 * Builds a {@see UsageSnapshot} from the provider, within a hard time and page budget.
 *
 * Every call is a read-only GET. Nothing here creates, attaches, detaches, re-indexes or deletes, and
 * the client methods it is allowed to touch are limited to the three listing/retrieval ones.
 *
 * The budget is the point of this class. It runs inside a web request that Nginx will cut off at 120s,
 * so it checks the elapsed time BEFORE every call rather than hoping the total stays small: with the
 * usage profile capped at 25s per call, spending the 45s budget and then making one final call still
 * lands near 70s, comfortably inside the limit. Exceeding a bound marks the snapshot `truncated` — a
 * partial sweep is reported as partial, never presented as a complete inventory.
 */
final readonly class CollectUsageSnapshotService
{
    private const STORE_PAGE_LIMIT = 100;
    private const MAX_STORE_PAGES = 10;
    private const FILE_PAGE_LIMIT = 100;
    private const MAX_FILE_PAGES_PER_STORE = 5;

    public function __construct(
        private OpenAiClientInterface $client,
        private UsageReconciler $reconciler,
        private UsageCalculator $calculator,
        private ClockInterface $clock,
        private UsageParams $params,
    ) {}

    public function collect(): UsageSnapshot
    {
        $startedAt = $this->clock->now();
        $problems = [];
        $truncated = false;

        try {
            [$stores, $storesTruncated] = $this->fetchStores();
            $truncated = $storesTruncated;
        } catch (AiException $e) {
            // The inventory is the one source without which there is no page. Surface it as a problem
            // and return an empty snapshot; the caller keeps the previous good one rather than saving
            // this.
            return new UsageSnapshot(
                syncedAt: $startedAt,
                stores: [],
                totals: UsageTotals::from([], $this->calculator),
                mappings: [],
                problems: [new SyncProblem(SyncProblem::SOURCE_VECTOR_STORES, $e->getMessage())],
                truncated: true,
                adminApiConfigured: $this->params->adminApiConfigured,
            );
        }

        $rows = [];
        foreach ($stores as $store) {
            $files = [];
            $fileProblem = null;

            if ($this->withinBudget($startedAt)) {
                try {
                    [$files, $filesTruncated] = $this->fetchFiles($store->id, $startedAt);
                    $truncated = $truncated || $filesTruncated;
                } catch (AiException $e) {
                    // One store's file list failing must not cost us the store, the totals, or the other
                    // stores' detail. The counts below come from the store object itself, so they stay
                    // correct regardless.
                    $fileProblem = $e->getMessage();
                    $problems[] = new SyncProblem(
                        SyncProblem::SOURCE_VECTOR_STORE_FILES,
                        $e->getMessage(),
                        $store->id,
                    );
                }
            } else {
                $truncated = true;
                $fileProblem = 'File detail was skipped because the sync time budget was exhausted.';
            }

            $rows[] = new UsageStoreRow(
                id: $store->id,
                name: $store->name,
                status: $store->status,
                usageBytes: $store->usageBytes,
                fileCounts: $store->fileCounts,
                createdAt: $store->createdAt === 0 ? null : $store->createdAt,
                lastActiveAt: $store->lastActiveAt,
                expiresAt: $store->expiresAt,
                metadata: $store->metadata,
                files: $files,
                fileDetailProblem: $fileProblem,
            );
        }

        return new UsageSnapshot(
            syncedAt: $this->clock->now(),
            stores: $rows,
            totals: UsageTotals::from($rows, $this->calculator),
            mappings: $this->reconciler->reconcile($rows),
            problems: $problems,
            truncated: $truncated,
            adminApiConfigured: $this->params->adminApiConfigured,
        );
    }

    /**
     * @return array{0: list<OpenAiVectorStore>, 1: bool} The stores, and whether the sweep was cut short.
     */
    private function fetchStores(): array
    {
        $startedAt = $this->clock->now();
        $stores = [];
        $after = null;
        $seenCursors = [];

        for ($page = 0; $page < self::MAX_STORE_PAGES; $page++) {
            if (!$this->withinBudget($startedAt)) {
                return [$stores, true];
            }

            $result = $this->client->listVectorStorePage(self::STORE_PAGE_LIMIT, $after);

            foreach ($result->data as $store) {
                $stores[] = $store;
            }

            // Stop unless the provider both says there is more AND gives a cursor we have not already
            // followed. A repeated or missing cursor would otherwise spin this loop forever.
            if (!$result->hasMore || $result->lastId === null || isset($seenCursors[$result->lastId])) {
                return [$stores, $result->hasMore && $result->lastId === null];
            }

            $seenCursors[$result->lastId] = true;
            $after = $result->lastId;
        }

        // Ran out of pages before the provider ran out of stores.
        return [$stores, true];
    }

    /**
     * @return array{0: list<UsageFileRow>, 1: bool}
     */
    private function fetchFiles(string $vectorStoreId, \DateTimeImmutable $startedAt): array
    {
        $files = [];
        $after = null;
        $seenCursors = [];

        for ($page = 0; $page < self::MAX_FILE_PAGES_PER_STORE; $page++) {
            if (!$this->withinBudget($startedAt)) {
                return [$files, true];
            }

            $result = $this->client->listVectorStoreFilePage($vectorStoreId, self::FILE_PAGE_LIMIT, $after);

            foreach ($result->data as $file) {
                $files[] = new UsageFileRow(
                    id: $file->id,
                    status: $file->status,
                    usageBytes: $file->usageBytes,
                    createdAt: $file->createdAt,
                    lastErrorCode: $file->lastErrorCode,
                    lastErrorMessage: $file->lastErrorMessage,
                    chunkingStrategy: $file->chunkingStrategy,
                );
            }

            if (!$result->hasMore || $result->lastId === null || isset($seenCursors[$result->lastId])) {
                return [$files, false];
            }

            $seenCursors[$result->lastId] = true;
            $after = $result->lastId;
        }

        return [$files, true];
    }

    private function withinBudget(\DateTimeImmutable $startedAt): bool
    {
        $elapsed = $this->clock->now()->getTimestamp() - $startedAt->getTimestamp();

        return $elapsed < $this->params->budgetSeconds;
    }
}
