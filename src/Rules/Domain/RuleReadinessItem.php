<?php

declare(strict_types=1);

namespace App\Rules\Domain;

use function mb_strlen;
use function mb_substr;

/**
 * One materialized rule document on the readiness page: its canonical rule ref, a store or common label, the
 * operational stage, the searchable OpenAI file id (if any) and the latest error (for failed items only).
 */
final readonly class RuleReadinessItem
{
    public function __construct(
        public int $documentId,
        public ?int $canonicalId,
        public string $title,
        public bool $isStoreSpecific,
        public ?string $storeName,
        public RuleReadinessStatus $status,
        public ?string $openaiFileId,
        public string $updatedAt,
        public ?string $error,
    ) {}

    public function typeLabel(): string
    {
        return $this->isStoreSpecific ? 'Store-specific' : 'Common';
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
