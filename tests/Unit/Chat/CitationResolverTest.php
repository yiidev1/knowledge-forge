<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Ai\Contract\Dto\IndexStatus;
use App\Ai\Contract\Dto\RawCitation;
use App\Chat\Application\Citation\CitationResolver;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\IndexedFileRole;
use App\Shared\Application\Correlation\CorrelationId;
use App\Shared\Infrastructure\Log\SafeLogContext;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\Document\InMemoryIndexedFileRepository;
use Codeception\Test\Unit;
use Psr\Log\NullLogger;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

/**
 * Resolving provider citations back to documents: original filename substituted, unresolvable and
 * cross-base ids dropped, duplicates collapsed.
 */
final class CitationResolverTest extends Unit
{
    private const KB = 2;
    private const DOC = 5;

    private InMemoryIndexedFileRepository $indexedFiles;
    private InMemoryDocumentRepository $documents;

    protected function _before(): void
    {
        $this->indexedFiles = new InMemoryIndexedFileRepository();
        $this->documents = new InMemoryDocumentRepository();

        $this->documents->seed(self::DOC, self::KB, DocumentStatus::Ready);
        $id = $this->indexedFiles->createPending(self::DOC, IndexedFileRole::DerivedMarkdown, 'derived/x.md');
        $this->indexedFiles->setUploaded($id, 'file_1', IndexStatus::Completed);
    }

    public function testResolvesToTheOriginalFilename(): void
    {
        $resolved = $this->resolver()->resolve([new RawCitation('file_1', 'x.md', 0)], self::KB);

        assertCount(1, $resolved);
        assertSame(self::DOC, $resolved[0]->documentId);
        assertSame('doc.pdf', $resolved[0]->filename); // NOT the derived .md name
    }

    public function testDropsUnresolvableFileId(): void
    {
        $resolved = $this->resolver()->resolve([new RawCitation('file_unknown', 'x.md', 0)], self::KB);

        assertCount(0, $resolved);
    }

    public function testDropsCitationFromAnotherKnowledgeBase(): void
    {
        $resolved = $this->resolver()->resolve([new RawCitation('file_1', 'x.md', 0)], 999);

        assertCount(0, $resolved);
    }

    public function testCollapsesDuplicateCitationsToOnePerDocument(): void
    {
        $resolved = $this->resolver()->resolve(
            [new RawCitation('file_1', 'x.md', 0), new RawCitation('file_1', 'x.md', 1)],
            self::KB,
        );

        assertCount(1, $resolved);
    }

    private function resolver(): CitationResolver
    {
        return new CitationResolver(
            $this->indexedFiles,
            $this->documents,
            new NullLogger(),
            new SafeLogContext(new SecretRedactor(), new CorrelationId('corr-test')),
        );
    }
}
