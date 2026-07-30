<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\KnowledgeBase;

use App\KnowledgeBase\Domain\KnowledgeBaseProvisioningRepositoryInterface;
use App\KnowledgeBase\Domain\ProvisioningCandidate;
use DateTimeImmutable;

use function count;

/**
 * In-memory provisioning repository. Tracks the vector-store lifecycle fields per knowledge base so the
 * atomic claim, backoff requeue and stuck recovery can be tested with {@see MutableClock}.
 */
final class InMemoryKnowledgeBaseProvisioningRepository implements KnowledgeBaseProvisioningRepositoryInterface
{
    /** @var array<int, array{name: string, slug: string, status: string, attempts: int, next: ?DateTimeImmutable, started: ?DateTimeImmutable, vectorStoreId: ?string, errorCode: ?string, errorMessage: ?string}> */
    private array $rows = [];

    public function seed(int $id, string $name, string $slug, string $status = 'pending', ?DateTimeImmutable $next = null): void
    {
        $this->rows[$id] = [
            'name' => $name,
            'slug' => $slug,
            'status' => $status,
            'attempts' => 0,
            'next' => $next,
            'started' => null,
            'vectorStoreId' => null,
            'errorCode' => null,
            'errorMessage' => null,
        ];
    }

    public function findProvisionable(int $limit, DateTimeImmutable $now): array
    {
        $result = [];
        foreach ($this->rows as $id => $row) {
            if ($row['status'] !== 'pending') {
                continue;
            }
            if ($row['next'] !== null && $row['next'] > $now) {
                continue;
            }

            $result[] = new ProvisioningCandidate($id, $row['name'], $row['slug'], $row['attempts']);
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public function claim(int $knowledgeBaseId, DateTimeImmutable $now): bool
    {
        if (($this->rows[$knowledgeBaseId]['status'] ?? null) !== 'pending') {
            return false;
        }

        $this->rows[$knowledgeBaseId]['status'] = 'provisioning';
        $this->rows[$knowledgeBaseId]['attempts']++;
        $this->rows[$knowledgeBaseId]['started'] = $now;

        return true;
    }

    public function markReady(int $knowledgeBaseId, string $vectorStoreId): void
    {
        $this->rows[$knowledgeBaseId]['status'] = 'ready';
        $this->rows[$knowledgeBaseId]['vectorStoreId'] = $vectorStoreId;
    }

    public function requeue(int $knowledgeBaseId, DateTimeImmutable $nextAttemptAt, ?string $errorCode, ?string $errorMessage): void
    {
        $this->rows[$knowledgeBaseId]['status'] = 'pending';
        $this->rows[$knowledgeBaseId]['next'] = $nextAttemptAt;
        $this->rows[$knowledgeBaseId]['errorCode'] = $errorCode;
        $this->rows[$knowledgeBaseId]['errorMessage'] = $errorMessage;
    }

    public function markFailed(int $knowledgeBaseId, ?string $errorCode, ?string $errorMessage): void
    {
        $this->rows[$knowledgeBaseId]['status'] = 'failed';
        $this->rows[$knowledgeBaseId]['errorCode'] = $errorCode;
        $this->rows[$knowledgeBaseId]['errorMessage'] = $errorMessage;
    }

    public function recoverStuck(DateTimeImmutable $threshold, DateTimeImmutable $now): int
    {
        $recovered = 0;
        foreach ($this->rows as $id => $row) {
            if ($row['status'] === 'provisioning' && $row['started'] !== null && $row['started'] < $threshold) {
                $this->rows[$id]['status'] = 'pending';
                $this->rows[$id]['next'] = null;
                $recovered++;
            }
        }

        return $recovered;
    }

    public function statusOf(int $knowledgeBaseId): ?string
    {
        return $this->rows[$knowledgeBaseId]['status'] ?? null;
    }

    public function vectorStoreIdOf(int $knowledgeBaseId): ?string
    {
        return $this->rows[$knowledgeBaseId]['vectorStoreId'] ?? null;
    }

    public function attemptsOf(int $knowledgeBaseId): int
    {
        return $this->rows[$knowledgeBaseId]['attempts'] ?? 0;
    }

    public function nextAttemptAtOf(int $knowledgeBaseId): ?DateTimeImmutable
    {
        return $this->rows[$knowledgeBaseId]['next'] ?? null;
    }
}
