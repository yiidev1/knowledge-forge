<?php

declare(strict_types=1);

namespace App\Document\Web\Download;

use App\Document\Application\ServeCanonicalDocumentService;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * GET …/documents/{id}/download — canonical local source as an attachment.
 */
final readonly class Action
{
    public function __construct(
        private ServeCanonicalDocumentService $serve,
        private KnowledgeBaseFinder $finder,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, #[RouteArgument] int $documentId): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $document = $this->serve->find($documentId, $knowledgeBase->id());

        return $this->serve->streamAttachment($document);
    }
}
