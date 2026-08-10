<?php

declare(strict_types=1);

namespace App\Rules\Domain;

use function mb_strlen;
use function mb_substr;

/**
 * One synced Order58 source rule on the readiness page: its source id, optional canonical/document refs,
 * classification label, operational stage, searchable OpenAI file id (if any) and latest error (failed only).
 */
final readonly class RuleReadinessItem
{
    public function __construct(
        public int $sourceId,
        public ?int $documentId,
        public ?int $canonicalId,
        public string $title,
        public string $classificationLabel,
        public ?string $storeName,
        public RuleReadinessStatus $status,
        public ?string $openaiFileId,
        public string $updatedAt,
        public ?string $error,
    ) {}

    public function typeLabel(): string
    {
        return $this->classificationLabel;
    }

    /** Whether a confirmed store link applies (for the Store column). */
    public function isStoreSpecific(): bool
    {
        return $this->storeName !== null && $this->storeName !== '';
    }

    /** A short, safe preview of the OpenAI file id (never a secret; file ids are not credentials). */
    public function shortFileId(): ?string
    {
        if ($this->openaiFileId === null || $this->openaiFileId === '') {
            return null;
        }

        return mb_strlen($this->openaiFileId) <= 14
            ? $this->openaiFileId
            : mb_substr($this->openaiFileId, 0, 10) . '…' . mb_substr($this->openaiFileId, -4);
    }
}
