<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * A searchable document generated from a canonical rule, and its lifecycle status.
 */
final readonly class RuleDocumentRow
{
    public function __construct(
        public string $knowledgeBaseName,
        public string $sourceType,
        public string $status,
    ) {}
}
