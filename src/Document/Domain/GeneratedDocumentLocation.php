<?php

declare(strict_types=1);

namespace App\Document\Domain;

/**
 * Where a generated document lives, carrying just enough to address it by the canonical
 * (knowledge base, source type, source ref) key — used by fleet-wide retire/report operations over a
 * projection type whose owning rule link may no longer exist.
 */
final readonly class GeneratedDocumentLocation
{
    public function __construct(
        public int $knowledgeBaseId,
        public string $sourceRef,
    ) {}
}
