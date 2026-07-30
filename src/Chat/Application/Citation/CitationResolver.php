<?php

declare(strict_types=1);

namespace App\Chat\Application\Citation;

use App\Ai\Contract\Dto\RawCitation;
use App\Chat\Domain\ResolvedCitation;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\IndexedFileRepositoryInterface;
use App\Shared\Infrastructure\Log\SafeLogContext;
use Psr\Log\LoggerInterface;

/**
 * Turns the provider's raw citations into ones the UI can trust: each provider file id is mapped back
 * through the index-file table to its document, and the ORIGINAL upload filename is substituted for the
 * provider-side name (which, for a vision-derived artifact, is a `.md` the user never saw).
 *
 * A file id that resolves to no live document in THIS knowledge base is dropped and logged — never
 * shown, never invented. Resolution is scoped by knowledge base, so a citation can never leak a
 * document from another base. Duplicates (a document cited by several chunks) collapse to one.
 */
final readonly class CitationResolver
{
    public function __construct(
        private IndexedFileRepositoryInterface $indexedFiles,
        private DocumentRepositoryInterface $documents,
        private LoggerInterface $logger,
        private SafeLogContext $logContext,
    ) {}

    /**
     * @param list<RawCitation> $rawCitations
     *
     * @return list<ResolvedCitation>
     */
    public function resolve(array $rawCitations, int $knowledgeBaseId): array
    {
        $resolved = [];
        $seenDocuments = [];

        foreach ($rawCitations as $raw) {
            $indexedFile = $this->indexedFiles->findByOpenaiFileId($raw->fileId);
            if ($indexedFile === null) {
                $this->drop($raw->fileId, 'no index file');
                continue;
            }

            $document = $this->documents->findByIdForKnowledgeBase($indexedFile->documentId, $knowledgeBaseId);
            if ($document === null) {
                $this->drop($raw->fileId, 'document not in this knowledge base');
                continue;
            }

            if (isset($seenDocuments[$document->id()])) {
                continue;
            }
            $seenDocuments[$document->id()] = true;

            $resolved[] = new ResolvedCitation($document->id(), $document->originalFilename(), $raw->fileId);
        }

        return $resolved;
    }

    private function drop(string $fileId, string $reason): void
    {
        // The id is an internal OpenAI reference, safe to log; never surfaced to the user. Routed through
        // SafeLogContext so the line carries the request's correlation id — a dropped citation is one of
        // the two ways an answer loses its grounding, and it has to be tied back to the answer that lost it.
        $this->logger->info(
            'Dropped unresolvable citation.',
            $this->logContext->build(['openai_file_id' => $fileId, 'reason' => $reason]),
        );
    }
}
