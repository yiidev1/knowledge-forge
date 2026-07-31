<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Document;

use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentListItem;
use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\EditableTextDocument;
use App\Document\Domain\TextDocumentRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Exception\IntegrityException;

use function array_reverse;
use function array_values;

/**
 * In-memory text-document repository. Records which mutation ran (content replace vs. metadata-only update
 * vs. enable/disable) so a test can assert that an unchanged edit never re-indexed. The database's unique
 * dedupe index is simulated by {@see failReplaceForChecksum}; the real constraint is covered by integration.
 */
final class InMemoryTextDocumentRepository implements TextDocumentRepositoryInterface
{
    /** @var array<int, array{kbId:int, sourceType:DocumentSourceType, title:string, sourceText:string, checksum:string, storedPath:string, sizeBytes:int, isEnabled:bool, kind:DocumentKind, status:DocumentStatus}> */
    private array $items = [];

    /** @var list<int> ids passed to replaceContent, in order */
    public array $replaced = [];

    /** @var list<int> ids passed to updateMetadata, in order */
    public array $metadataUpdated = [];

    /** @var list<array{id:int, enabled:bool}> */
    public array $enabledCalls = [];

    /** @var array<string, true> checksums that collide on replaceContent */
    private array $duplicateChecksums = [];

    public function seed(
        int $id,
        int $kbId,
        DocumentSourceType $sourceType,
        string $title,
        string $sourceText,
        string $checksum,
        string $storedPath,
        bool $isEnabled = true,
    ): void {
        $this->items[$id] = [
            'kbId' => $kbId,
            'sourceType' => $sourceType,
            'title' => $title,
            'sourceText' => $sourceText,
            'checksum' => $checksum,
            'storedPath' => $storedPath,
            'sizeBytes' => strlen($sourceText),
            'isEnabled' => $isEnabled,
            'kind' => DocumentKind::Text,
            'status' => DocumentStatus::Ready,
        ];
    }

    /** Make the next replaceContent for this checksum behave like a hit on the unique dedupe index. */
    public function failReplaceForChecksum(string $checksum): void
    {
        $this->duplicateChecksums[$checksum] = true;
    }

    public function findEditable(int $documentId, int $knowledgeBaseId): ?EditableTextDocument
    {
        $row = $this->items[$documentId] ?? null;
        if ($row === null || $row['kbId'] !== $knowledgeBaseId) {
            return null;
        }

        return new EditableTextDocument(
            id: $documentId,
            sourceType: $row['sourceType'],
            title: $row['title'],
            sourceText: $row['sourceText'],
            checksum: $row['checksum'],
            storedPath: $row['storedPath'],
        );
    }

    public function replaceContent(
        int $documentId,
        string $title,
        string $sourceText,
        string $checksum,
        int $sizeBytes,
        DateTimeImmutable $now,
    ): void {
        if (isset($this->duplicateChecksums[$checksum])) {
            throw new IntegrityException('Duplicate entry for dedupe_hash.');
        }

        $row = $this->items[$documentId] ?? null;
        if ($row !== null) {
            $row['title'] = $title;
            $row['sourceText'] = $sourceText;
            $row['checksum'] = $checksum;
            $row['sizeBytes'] = $sizeBytes;
            $row['isEnabled'] = true;
            $row['status'] = DocumentStatus::Queued;
            $this->items[$documentId] = $row;
        }
        $this->replaced[] = $documentId;
    }

    public function updateMetadata(int $documentId, string $title, string $sourceText, DateTimeImmutable $now): void
    {
        $row = $this->items[$documentId] ?? null;
        if ($row !== null) {
            $row['title'] = $title;
            $row['sourceText'] = $sourceText;
            $this->items[$documentId] = $row;
        }
        $this->metadataUpdated[] = $documentId;
    }

    public function setEnabled(int $documentId, int $knowledgeBaseId, bool $enabled, DateTimeImmutable $now): void
    {
        $row = $this->items[$documentId] ?? null;
        if ($row !== null && $row['kbId'] === $knowledgeBaseId) {
            $row['isEnabled'] = $enabled;
            $this->items[$documentId] = $row;
        }
        $this->enabledCalls[] = ['id' => $documentId, 'enabled' => $enabled];
    }

    public function findListForKnowledgeBase(int $knowledgeBaseId): array
    {
        $result = [];
        foreach ($this->items as $id => $row) {
            if ($row['kbId'] !== $knowledgeBaseId) {
                continue;
            }
            $result[] = new DocumentListItem(
                id: $id,
                title: $row['title'],
                sourceType: $row['sourceType'],
                kind: $row['kind'],
                status: $row['status'],
                isEnabled: $row['isEnabled'],
                sizeBytes: $row['sizeBytes'],
                errorMessage: null,
                createdAt: new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
            );
        }

        return array_values(array_reverse($result));
    }

    public function isEnabled(int $documentId): ?bool
    {
        return $this->items[$documentId]['isEnabled'] ?? null;
    }
}
