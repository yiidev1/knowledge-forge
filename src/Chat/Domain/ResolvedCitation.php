<?php

declare(strict_types=1);

namespace App\Chat\Domain;

use function is_array;

/**
 * A citation after the provider's file id has been resolved back to a knowledge-base document.
 *
 * This is what the UI shows: the ORIGINAL upload filename (never the derived `.md` name), linked to the
 * document it came from. A citation the resolver could not map to a live document is dropped, never
 * displayed — the application never shows a source it cannot stand behind.
 */
final readonly class ResolvedCitation
{
    public function __construct(
        public int $documentId,
        public string $filename,
        public string $fileId,
    ) {}

    /**
     * @return array{document_id: int, filename: string, file_id: string}
     */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'filename' => $this->filename,
            'file_id' => $this->fileId,
        ];
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data) || !isset($data['document_id'], $data['filename'], $data['file_id'])) {
            return null;
        }

        return new self((int) $data['document_id'], (string) $data['filename'], (string) $data['file_id']);
    }
}
