<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\AudioToText;

use App\AudioToText\Domain\Tts\TtsEnqueueOutcome;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsRendition;
use App\AudioToText\Domain\Tts\TtsRenditionRepositoryInterface;
use App\AudioToText\Domain\Tts\TtsStatus;
use DateTimeImmutable;

use function array_values;
use function in_array;
use function bin2hex;
use function random_bytes;

/**
 * An in-memory rendition store, for testing the decisions rather than the SQL.
 *
 * It reproduces the **one** behaviour the real repository has that the rest of the system depends on:
 * {@see enqueue()} refuses a rendition that is already queued or generating. That rule is what stops a
 * double click, a refresh and the automatic trigger from turning into three charges, so a fake that
 * happily re-queued would let a test pass while the thing it was testing was broken.
 *
 * Everything else here is storage.
 */
final class InMemoryTtsRenditionRepository implements TtsRenditionRepositoryInterface
{
    /** @var array<string, TtsRendition> keyed by "jobId:outputType" */
    private array $rows = [];

    private int $nextId = 1;

    /** How many times a row was actually queued — the number a cost-protection test asserts on. */
    public int $enqueueCount = 0;

    public function seed(TtsRendition $rendition): void
    {
        $this->rows[$rendition->jobId . ':' . $rendition->outputType->value] = $rendition;
    }

    public function findForJob(int $jobId, TtsOutputType $outputType): ?TtsRendition
    {
        return $this->rows[$jobId . ':' . $outputType->value] ?? null;
    }

    public function forJobs(array $jobIds): array
    {
        $byJob = [];

        foreach ($this->rows as $rendition) {
            if (in_array($rendition->jobId, $jobIds, true)) {
                $byJob[$rendition->jobId][$rendition->outputType->value] = $rendition;
            }
        }

        return $byJob;
    }

    public function enqueue(
        int $jobId,
        TtsOutputType $outputType,
        string $requestedHash,
        ?int $adminId,
        string $provider,
    ): TtsEnqueueOutcome {
        $existing = $this->findForJob($jobId, $outputType);

        // The rule that matters. A row mid-flight is left exactly as it is, however the request arrived.
        if ($existing !== null && $existing->status->isInFlight()) {
            return TtsEnqueueOutcome::AlreadyRunning;
        }

        $now = new DateTimeImmutable('2026-09-18 12:00:00');

        $this->rows[$jobId . ':' . $outputType->value] = new TtsRendition(
            $existing?->id ?? $this->nextId++,
            $jobId,
            $outputType,
            TtsStatus::Queued,
            bin2hex(random_bytes(16)),
            $requestedHash,
            // A previous file survives a re-queue untouched: that is what keeps old audio playable while
            // a replacement is generated.
            $existing?->fileName,
            $existing?->fileHash,
            $existing?->fileRenderKey,
            $existing?->fileBytes,
            $existing?->characterCount,
            $existing?->requestCount,
            $provider,
            $existing?->modelCustomer,
            $existing?->modelAgent,
            $existing?->attempts ?? 0,
            null,
            $adminId,
            null,
            $existing?->createdAt ?? $now,
            $now,
            null,
            $existing?->generatedAt,
        );

        $this->enqueueCount++;

        return TtsEnqueueOutcome::Queued;
    }

    public function claim(int $candidates = 10): ?TtsRendition
    {
        foreach ($this->rows as $key => $rendition) {
            if ($rendition->status !== TtsStatus::Queued) {
                continue;
            }

            $claimed = $this->with($rendition, TtsStatus::Generating, $rendition->attempts + 1);
            $this->rows[$key] = $claimed;

            return $claimed;
        }

        return null;
    }

    public function markReady(
        int $id,
        string $attemptToken,
        string $fileName,
        string $fileHash,
        string $fileRenderKey,
        int $fileBytes,
        int $characterCount,
        int $requestCount,
        ?string $modelCustomer,
        ?string $modelAgent,
    ): bool {
        foreach ($this->rows as $key => $rendition) {
            if ($rendition->id !== $id) {
                continue;
            }

            // The compare-and-swap: a superseded attempt writes nothing.
            if ($rendition->attemptToken !== $attemptToken) {
                return false;
            }

            $this->rows[$key] = new TtsRendition(
                $rendition->id,
                $rendition->jobId,
                $rendition->outputType,
                TtsStatus::Ready,
                $rendition->attemptToken,
                $rendition->requestedHash,
                $fileName,
                $fileHash,
                $fileRenderKey,
                $fileBytes,
                $characterCount,
                $requestCount,
                $rendition->provider,
                $modelCustomer,
                $modelAgent,
                $rendition->attempts,
                null,
                $rendition->requestedByAdminId,
                $rendition->requestedByUsername,
                $rendition->createdAt,
                $rendition->updatedAt,
                null,
                new DateTimeImmutable('2026-09-18 12:00:00'),
            );

            return true;
        }

        return false;
    }

    public function markFailed(int $id, string $attemptToken, string $message): bool
    {
        foreach ($this->rows as $key => $rendition) {
            if ($rendition->id !== $id) {
                continue;
            }

            if ($rendition->attemptToken !== $attemptToken) {
                return false;
            }

            // file_name and file_hash are deliberately carried over: a failed regeneration leaves the
            // audio it was replacing exactly as playable as it was.
            $this->rows[$key] = $this->with($rendition, TtsStatus::Failed, $rendition->attempts, $message);

            return true;
        }

        return false;
    }

    public function recoverStale(int $olderThanSeconds): int
    {
        $recovered = 0;

        foreach ($this->rows as $key => $rendition) {
            if ($rendition->status === TtsStatus::Generating) {
                $this->rows[$key] = $this->with($rendition, TtsStatus::Failed, $rendition->attempts, 'Interrupted.');
                $recovered++;
            }
        }

        return $recovered;
    }

    public function generatingJobPublicIds(): array
    {
        return [];
    }

    public function countByStatus(TtsStatus $status): int
    {
        $count = 0;

        foreach ($this->rows as $rendition) {
            if ($rendition->status === $status) {
                $count++;
            }
        }

        return $count;
    }

    /** @return list<TtsRendition> */
    public function all(): array
    {
        return array_values($this->rows);
    }

    private function with(
        TtsRendition $r,
        TtsStatus $status,
        int $attempts,
        ?string $error = null,
    ): TtsRendition {
        return new TtsRendition(
            $r->id,
            $r->jobId,
            $r->outputType,
            $status,
            $r->attemptToken,
            $r->requestedHash,
            $r->fileName,
            $r->fileHash,
            $r->fileRenderKey,
            $r->fileBytes,
            $r->characterCount,
            $r->requestCount,
            $r->provider,
            $r->modelCustomer,
            $r->modelAgent,
            $attempts,
            $error,
            $r->requestedByAdminId,
            $r->requestedByUsername,
            $r->createdAt,
            $r->updatedAt,
            $status === TtsStatus::Generating ? new DateTimeImmutable('2026-09-18 12:00:00') : null,
            $r->generatedAt,
        );
    }
}
