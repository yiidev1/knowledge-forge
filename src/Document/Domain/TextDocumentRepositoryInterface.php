<?php

declare(strict_types=1);

namespace App\Document\Domain;

use DateTimeImmutable;

/**
 * Text-document operations over the `documents` table that the manual-text edit flow, the enable/disable
 * toggle, and the knowledge-base document list need — all id- and knowledge-base-scoped. Kept separate
 * from {@see DocumentRepositoryInterface} so the upload path and its in-memory test double are untouched.
 */
interface TextDocumentRepositoryInterface
{
    public function findEditable(int $documentId, int $knowledgeBaseId): ?EditableTextDocument;

    /**
     * Applies changed manual-text content and requeues the document fresh for re-indexing. Throws
     * {@see \Yiisoft\Db\Exception\IntegrityException} if the new content duplicates another live document
     * in the same knowledge base.
     */
    public function replaceContent(
        int $documentId,
        string $title,
        string $sourceText,
        string $checksum,
        int $sizeBytes,
        DateTimeImmutable $now,
    ): void;

    /**
     * Updates only the title/original text when the normalized content did not change — no re-index.
     */
    public function updateMetadata(int $documentId, string $title, string $sourceText, DateTimeImmutable $now): void;

    public function setEnabled(int $documentId, int $knowledgeBaseId, bool $enabled, DateTimeImmutable $now): void;

    /**
     * @return list<DocumentListItem> Newest first, for the knowledge-base detail page.
     */
    public function findListForKnowledgeBase(int $knowledgeBaseId): array;
}
