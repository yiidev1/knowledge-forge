<?php

declare(strict_types=1);

namespace App\Document\Application\Processing;

use App\Document\Domain\Document;
use RuntimeException;

use function sprintf;

/**
 * Selects the processor for a document. Adding a new document kind (DOCX, TXT) is a new processor
 * registered here — the processing service does not change.
 */
final readonly class DocumentProcessorRegistry
{
    /**
     * @param iterable<DocumentProcessorInterface> $processors
     */
    public function __construct(
        private iterable $processors,
    ) {}

    public function for(Document $document): DocumentProcessorInterface
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($document)) {
                return $processor;
            }
        }

        throw new RuntimeException(sprintf('No processor supports document kind "%s".', $document->kind()->value));
    }
}
