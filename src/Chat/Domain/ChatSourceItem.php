<?php

declare(strict_types=1);

namespace App\Chat\Domain;

use App\Document\Domain\DocumentDisplayStatus;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentSourceType;
use DateTimeImmutable;

/**
 * One knowledge document on a "sources available to this chat" page.
 *
 * `$retrievable` is the honest answer to "can this chat's retrieval actually reach this document right now?"
 * — it is the durable index-file snapshot ({@see \App\Document\Domain\DocumentRepositoryInterface::findUsableDocumentIds()})
 * AND the surface's {@see ChatRetrievalScope}. It is deliberately NOT `documents.status`, which flips to
 * `queued` during a refresh while the previous completed file stays searchable.
 */
final readonly class ChatSourceItem
{
    public function __construct(
        public int $documentId,
        public string $title,
        public DocumentSourceType $sourceType,
        public DocumentKind $kind,
        public DocumentDisplayStatus $displayStatus,
        public bool $retrievable,
        public DateTimeImmutable $createdAt,
        /**
         * The document's own text — the artifact retrieval actually reads — or null when it could not be
         * loaded (a binary original with no derived text yet, or a missing file). Truncated for display.
         */
        public ?string $preview = null,
        public bool $previewTruncated = false,
    ) {}

    public function hasPreview(): bool
    {
        return $this->preview !== null && $this->preview !== '';
    }

    /**
     * A human label for the document's origin, so the page can say where the content came from without the
     * reader knowing the internal enum values.
     */
    public function typeLabel(): string
    {
        return match ($this->sourceType) {
            DocumentSourceType::Order58StoreProfile => 'Store profile',
            DocumentSourceType::Order58Knowledge => 'Order58 knowledge',
            DocumentSourceType::Order58RuleStore => 'Store rule',
            DocumentSourceType::Order58RuleGlobal => 'Global rule',
            DocumentSourceType::Order58RuleCommon => 'Common rule',
            DocumentSourceType::UploadedPdf => 'Uploaded PDF',
            DocumentSourceType::UploadedImage => 'Uploaded image',
            DocumentSourceType::UploadedText => 'Uploaded text',
            DocumentSourceType::ManualText => 'Manual text',
        };
    }

    /**
     * Why a document is present but not reachable — shown instead of a bare "No", so the reader can tell a
     * still-indexing document from one retrieval is forbidden to use.
     */
    public function unavailableReason(): ?string
    {
        if ($this->retrievable) {
            return null;
        }

        return $this->displayStatus === DocumentDisplayStatus::Disabled
            ? 'Disabled by an administrator'
            : 'No completed index yet';
    }
}
