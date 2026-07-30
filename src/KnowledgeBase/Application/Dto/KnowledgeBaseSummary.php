<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Application\Dto;

use App\KnowledgeBase\Domain\KnowledgeBase;

/**
 * A knowledge base paired with its aggregate counts, for the list screen.
 *
 * Document counts arrive in Phase 4; for now the summary carries rule counts, which is enough to give
 * the list meaningful at-a-glance information without an N+1 query.
 */
final readonly class KnowledgeBaseSummary
{
    public function __construct(
        public KnowledgeBase $knowledgeBase,
        public int $ruleCount,
        public int $enabledRuleCount,
    ) {}
}
