<?php

declare(strict_types=1);

namespace App\Document\Application\Processing;

use App\Document\Application\Storage\DocumentStorageInterface;
use App\Document\Domain\Document;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\IndexedFileRole;

/**
 * Ingests documents that are already normalized UTF-8 text: the Order58-generated store-profile and
 * knowledge documents, and — in later phases — uploaded .txt/.md and manual text. There is no extraction
 * and no AI call: the stored text is indexed directly as a source file.
 *
 * This one processor serves every text source type, so adding uploaded_text / manual_text in Phase 3
 * needs no change here — only a validator/creation path that produces a `kind = text` document.
 */
final readonly class TextDocumentProcessor implements DocumentProcessorInterface
{
    public function __construct(
        private DocumentStorageInterface $storage,
    ) {}

    public function supports(Document $document): bool
    {
        return $document->kind() === DocumentKind::Text;
    }

    public function produce(Document $document): array
    {
        return [new Indexable(
            IndexedFileRole::Source,
            $this->storage->readStream($document->storedPath()),
            $document->storageToken() . '.md',
            null,
        )];
    }
}
