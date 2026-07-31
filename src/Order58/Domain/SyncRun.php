<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use DateTimeImmutable;

/**
 * A synchronization run: read model for the worker and the admin UI. Mutations go through the repository;
 * this entity carries current state for rendering and for the drainer's decisions.
 */
final readonly class SyncRun
{
    public function __construct(
        private int $id,
        private Order58SyncType $type,
        private ?int $scopeRef,
        private SyncRunStatus $status,
        private int $attempts,
        private ?int $requestedByAdminId,
        private SyncProgress $progress,
        private ?DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $completedAt,
        private ?string $errorCode,
        private ?string $errorMessage,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {}

    public function id(): int
    {
        return $this->id;
    }

    public function type(): Order58SyncType
    {
        return $this->type;
    }

    public function scopeRef(): ?int
    {
        return $this->scopeRef;
    }

    public function status(): SyncRunStatus
    {
        return $this->status;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function requestedByAdminId(): ?int
    {
        return $this->requestedByAdminId;
    }

    public function progress(): SyncProgress
    {
        return $this->progress;
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
