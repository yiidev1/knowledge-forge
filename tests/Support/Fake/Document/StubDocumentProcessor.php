<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Document;

use App\Document\Application\Processing\DocumentProcessorInterface;
use App\Document\Application\Processing\Indexable;
use App\Document\Domain\Document;
use App\Document\Domain\Exception\ManualReviewRequired;
use App\Document\Domain\IndexedFileRole;
use GuzzleHttp\Psr7\Utils;
use Throwable;

/**
 * A processor whose behaviour the test dictates: it supports any document and either returns a scripted
 * list of {@see Indexable}s or throws a scripted exception (a {@see ManualReviewRequired} or an
 * AiException), so the processing service's state machine can be tested independently of the real
 * PDF/image processors.
 */
final class StubDocumentProcessor implements DocumentProcessorInterface
{
    private ?Throwable $throw = null;

    /** @var list<Indexable> */
    private array $indexables;

    public function __construct()
    {
        $this->indexables = [
            new Indexable(IndexedFileRole::Source, Utils::streamFor('%PDF-1.7 body'), 'doc.pdf', null),
        ];
    }

    /** @param list<Indexable> $indexables */
    public function returns(array $indexables): void
    {
        $this->indexables = $indexables;
    }

    public function willThrow(Throwable $throw): void
    {
        $this->throw = $throw;
    }

    public function willRequireManualReview(string $reason = 'Needs review.'): void
    {
        $this->throw = new ManualReviewRequired($reason);
    }

    public function supports(Document $document): bool
    {
        return true;
    }

    public function produce(Document $document): array
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->indexables;
    }
}
