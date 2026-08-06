<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * Header facts about the hidden Global/Common Rules knowledge base, for the URL-only diagnostic page.
 */
final readonly class RuleReadinessBaseInfo
{
    public function __construct(
        public int $knowledgeBaseId,
        public string $name,
        public string $slug,
        public string $vectorStoreStatus,
    ) {}
}
