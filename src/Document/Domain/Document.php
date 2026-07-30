<?php

declare(strict_types=1);

namespace App\Document\Domain;

use DateTimeImmutable;

/**
 * An uploaded document.
 *
 * Read model for rendering and decisions. The stored path and storage token are infrastructure detail
 * and are never rendered; only {@see originalFilename()} is shown to a user, always HTML-escaped. The
 * error message, when present, is already redacted and safe to display.
 */
final readonly class Document
{
    public function __construct(
        private int $id,
        private int $knowledgeBaseId,
        private string $originalFilename,
        private string $storedPath,
        private string $storageToken,
        private string $mimeType,
        private string $extension,
        private int $sizeBytes,
        private string $checksumSha256,
        private DocumentKind $kind,
        private DocumentStatus $status,
        private int $processingAttempts,
        private ?string $errorCode,
        private ?string $errorMessage,
        private ?DateTimeImmutable $processedAt,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {}

    public function id(): int
    {
        return $this->id;
    }

    public function knowledgeBaseId(): int
    {
        return $this->knowledgeBaseId;
    }

    public function originalFilename(): string
    {
        return $this->originalFilename;
    }

    /**
     * Path relative to the storage root. Infrastructure detail — never render this.
     */
    public function storedPath(): string
    {
        return $this->storedPath;
    }

    public function storageToken(): string
    {
        return $this->storageToken;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function checksumSha256(): string
    {
        return $this->checksumSha256;
    }

    public function kind(): DocumentKind
    {
        return $this->kind;
    }

    public function status(): DocumentStatus
    {
        return $this->status;
    }

    public function processingAttempts(): int
    {
        return $this->processingAttempts;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Already redacted and length-capped when written; safe to show to an administrator.
     */
    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function processedAt(): ?DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Human-readable size for the UI, computed here so templates stay logic-free.
     */
    public function humanSize(): string
    {
        if ($this->sizeBytes < 1024) {
            return $this->sizeBytes . ' B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log((float) $this->sizeBytes, 1024));
        $power = max(1, min($power, count($units) - 1));
        $value = $this->sizeBytes / (1024 ** $power);

        return sprintf('%.1f %s', $value, $units[$power]);
    }
}
