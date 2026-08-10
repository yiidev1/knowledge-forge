<?php

declare(strict_types=1);

namespace App\Rules\Application;

use App\Document\Domain\DocumentRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBase;

/**
 * Decides whether the hidden Global Rules knowledge base is ready for dedicated Rule Chat.
 *
 * Deliberately self-contained: it does NOT use (or modify) the store {@see \App\Chat\Application\ChatAvailabilityPolicy}.
 * The global base qualifies only when its vector store is ready AND it holds at least one usable Ready global
 * rule document (enabled, non-deleted, completed index with openai_file_id, not pending removal). Synced but
 * non-indexed Order58 rules never enable Rule Chat.
 */
final readonly class CommonRulesReadiness
{
    public function __construct(
        private EnsureCommonRulesKnowledgeBaseService $globalBase,
        private DocumentRepositoryInterface $documents,
    ) {}

    /**
     * The ready global base, or null when it does not exist / is not provisioned / has no usable global rule.
     */
    public function readyKnowledgeBase(): ?KnowledgeBase
    {
        $kb = $this->globalBase->find();
        if ($kb === null || !$kb->isReadyForChat()) {
            return null;
        }

        return $this->documents->hasUsableGlobalRuleDocument($kb->id()) ? $kb : null;
    }
}
