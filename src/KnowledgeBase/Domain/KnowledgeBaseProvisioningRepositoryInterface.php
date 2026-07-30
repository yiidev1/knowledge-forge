<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Domain;

use DateTimeImmutable;

/**
 * Persistence for the background provisioning of a knowledge base's vector store.
 *
 * The claim is atomic (pending → provisioning in one conditional update), so two concurrent workers can
 * never provision the same knowledge base. Recovery returns rows stuck in `provisioning` — a worker
 * that died mid-create — to `pending` so they are retried.
 */
interface KnowledgeBaseProvisioningRepositoryInterface
{
    /**
     * @return list<ProvisioningCandidate> Pending knowledge bases whose backoff has elapsed, oldest first.
     */
    public function findProvisionable(int $limit, DateTimeImmutable $now): array;

    /**
     * Atomically transitions one knowledge base from `pending` to `provisioning`, incrementing its
     * attempt count and stamping the start time.
     *
     * @return bool True if this worker won the claim (the row was pending); false otherwise.
     */
    public function claim(int $knowledgeBaseId, DateTimeImmutable $now): bool;

    public function markReady(int $knowledgeBaseId, string $vectorStoreId): void;

    /**
     * Returns the knowledge base to `pending` with a future retry time and a safe error message.
     */
    public function requeue(int $knowledgeBaseId, DateTimeImmutable $nextAttemptAt, ?string $errorCode, ?string $errorMessage): void;

    public function markFailed(int $knowledgeBaseId, ?string $errorCode, ?string $errorMessage): void;

    /**
     * Returns knowledge bases stuck in `provisioning` since before $threshold to `pending`.
     *
     * @return int Number recovered.
     */
    public function recoverStuck(DateTimeImmutable $threshold, DateTimeImmutable $now): int;
}
