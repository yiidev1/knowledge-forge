<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Document;

use App\Document\Domain\Document;
use App\Document\Domain\DocumentProcessingRepositoryInterface;
use App\Document\Domain\DocumentStatus;
use DateTimeImmutable;

use function count;
use function array_keys;
use function in_array;
use function uksort;

/**
 * In-memory processing repository. Models the worker-facing lifecycle fields the {@see Document} entity
 * does not carry (next-attempt time, processing-start time) alongside its status and attempts, so
 * backoff, claiming and stuck recovery can be exercised deterministically with {@see MutableClock}.
 */
final class InMemoryDocumentProcessingRepository implements DocumentProcessingRepositoryInterface
{
    /** @var array<int, Document> Immutable templates for the static columns. */
    private array $templates = [];

    /** @var array<int, DocumentStatus> */
    private array $status = [];

    /** @var array<int, int> */
    private array $attempts = [];

    /** @var array<int, ?DateTimeImmutable> */
    private array $nextAttemptAt = [];

    /** @var array<int, ?DateTimeImmutable> */
    private array $startedAt = [];

    /** @var array<int, ?string> */
    private array $errorCode = [];

    /** @var array<int, ?string> */
    private array $errorMessage = [];

    public function seed(Document $document, ?DateTimeImmutable $nextAttemptAt = null): void
    {
        $id = $document->id();
        $this->templates[$id] = $document;
        $this->status[$id] = $document->status();
        $this->attempts[$id] = $document->processingAttempts();
        $this->nextAttemptAt[$id] = $nextAttemptAt;
        $this->startedAt[$id] = null;
        $this->errorCode[$id] = $document->errorCode();
        $this->errorMessage[$id] = $document->errorMessage();
    }

    public function findProcessable(int $limit, DateTimeImmutable $now): array
    {
        $due = [];
        foreach ($this->templates as $id => $_) {
            if (!in_array($this->status[$id], [DocumentStatus::Queued, DocumentStatus::Indexing], true)) {
                continue;
            }

            $next = $this->nextAttemptAt[$id];
            if ($next !== null && $next > $now) {
                continue;
            }

            $due[$id] = $next;
        }

        // Mirrors the SQL ordering: a document with a due next_attempt_at — an indexing poll, or a
        // retry after a transient failure — is finishing work already started, so it comes before one
        // that has never been scheduled. Keeping the fake honest about this matters, or the drainer
        // tests would pass against an ordering the database does not actually produce.
        uksort($due, static function (int $a, int $b) use ($due): int {
            $aScheduled = $due[$a] !== null;
            $bScheduled = $due[$b] !== null;

            if ($aScheduled !== $bScheduled) {
                return $aScheduled ? -1 : 1;
            }

            if ($aScheduled && $due[$a] != $due[$b]) {
                return $due[$a] <=> $due[$b];
            }

            return $a <=> $b;
        });

        $result = [];
        foreach (array_keys($due) as $id) {
            $result[] = $this->rebuild($id);
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public function find(int $documentId): ?Document
    {
        return isset($this->templates[$documentId]) ? $this->rebuild($documentId) : null;
    }

    public function claim(int $documentId, DocumentStatus $expected, DateTimeImmutable $now): bool
    {
        if (($this->status[$documentId] ?? null) !== $expected) {
            return false;
        }

        $this->status[$documentId] = DocumentStatus::Processing;
        $this->startedAt[$documentId] = $now;
        if ($expected === DocumentStatus::Queued) {
            $this->attempts[$documentId]++;
        }

        return true;
    }

    public function markReady(int $documentId, DateTimeImmutable $now): void
    {
        $this->status[$documentId] = DocumentStatus::Ready;
        $this->errorCode[$documentId] = null;
        $this->errorMessage[$documentId] = null;
    }

    public function markIndexing(int $documentId, DateTimeImmutable $nextAttemptAt): void
    {
        $this->status[$documentId] = DocumentStatus::Indexing;
        $this->nextAttemptAt[$documentId] = $nextAttemptAt;
    }

    public function requeue(int $documentId, DateTimeImmutable $nextAttemptAt, ?string $errorCode, ?string $errorMessage): void
    {
        $this->status[$documentId] = DocumentStatus::Queued;
        $this->nextAttemptAt[$documentId] = $nextAttemptAt;
        $this->errorCode[$documentId] = $errorCode;
        $this->errorMessage[$documentId] = $errorMessage;
    }

    public function markFailed(int $documentId, ?string $errorCode, ?string $errorMessage): void
    {
        $this->status[$documentId] = DocumentStatus::Failed;
        $this->errorCode[$documentId] = $errorCode;
        $this->errorMessage[$documentId] = $errorMessage;
    }

    public function recoverStuck(DateTimeImmutable $threshold, DateTimeImmutable $now): int
    {
        $recovered = 0;
        foreach ($this->templates as $id => $_) {
            $started = $this->startedAt[$id];
            if ($this->status[$id] === DocumentStatus::Processing && $started !== null && $started < $threshold) {
                $this->status[$id] = DocumentStatus::Queued;
                $this->nextAttemptAt[$id] = null;
                $recovered++;
            }
        }

        return $recovered;
    }

    public function statusOf(int $documentId): ?DocumentStatus
    {
        return $this->status[$documentId] ?? null;
    }

    public function attemptsOf(int $documentId): int
    {
        return $this->attempts[$documentId] ?? 0;
    }

    public function nextAttemptAtOf(int $documentId): ?DateTimeImmutable
    {
        return $this->nextAttemptAt[$documentId] ?? null;
    }

    private function rebuild(int $id): Document
    {
        $t = $this->templates[$id];

        return new Document(
            id: $t->id(),
            knowledgeBaseId: $t->knowledgeBaseId(),
            originalFilename: $t->originalFilename(),
            storedPath: $t->storedPath(),
            storageToken: $t->storageToken(),
            mimeType: $t->mimeType(),
            extension: $t->extension(),
            sizeBytes: $t->sizeBytes(),
            checksumSha256: $t->checksumSha256(),
            kind: $t->kind(),
            status: $this->status[$id],
            processingAttempts: $this->attempts[$id],
            errorCode: $this->errorCode[$id],
            errorMessage: $this->errorMessage[$id],
            processedAt: $t->processedAt(),
            createdAt: $t->createdAt(),
            updatedAt: $t->updatedAt(),
        );
    }
}
