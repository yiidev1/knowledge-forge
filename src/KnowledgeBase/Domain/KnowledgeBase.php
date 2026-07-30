<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Domain;

use DateTimeImmutable;

/**
 * A knowledge base: a named collection of documents, its answering rules, and the OpenAI vector store
 * that backs retrieval.
 *
 * Read model. Mutations go through application services and the repository; the entity carries current
 * state for rendering and for decisions ("is it ready for chat?"). The vector store id and provisioning
 * bookkeeping live here but are surfaced to the UI only as a status, never as a raw identifier.
 */
final readonly class KnowledgeBase
{
    public function __construct(
        private int $id,
        private string $name,
        private string $slug,
        private ?string $description,
        private ?string $systemInstructions,
        private ?string $openaiVectorStoreId,
        private VectorStoreStatus $vectorStoreStatus,
        private ?string $vectorStoreError,
        private KnowledgeBaseStatus $status,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {}

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function systemInstructions(): ?string
    {
        return $this->systemInstructions;
    }

    /**
     * The vector store identifier is infrastructure detail: never put it in a URL or render it to a
     * user. It is exposed only so ingestion and chat services can address the store.
     */
    public function openaiVectorStoreId(): ?string
    {
        return $this->openaiVectorStoreId;
    }

    public function vectorStoreStatus(): VectorStoreStatus
    {
        return $this->vectorStoreStatus;
    }

    /**
     * A safe, already-redacted provisioning error message for display, or null when there is none.
     */
    public function vectorStoreError(): ?string
    {
        return $this->vectorStoreError;
    }

    public function status(): KnowledgeBaseStatus
    {
        return $this->status;
    }

    public function isArchived(): bool
    {
        return $this->status === KnowledgeBaseStatus::Archived;
    }

    /**
     * Whether the knowledge base can currently be chatted with: active and with a ready vector store.
     * Document readiness (at least one indexed document) is layered on in Phase 7.
     */
    public function isReadyForChat(): bool
    {
        return $this->status->isActive() && $this->vectorStoreStatus->isReady();
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
