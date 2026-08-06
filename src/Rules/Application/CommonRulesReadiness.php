<?php

declare(strict_types=1);

namespace App\Rules\Application;

use App\Document\Domain\DocumentRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBase;

/**
 * Decides whether the hidden Global Rules knowledge base is ready to answer as the stage-2 chat fallback.
 *
 * Deliberately self-contained: it does NOT use (or modify) the store {@see \App\Chat\Application\ChatAvailabilityPolicy}.
 * The global base qualifies only when its vector store is ready AND it holds at least one usable, completed,
 * enabled global rule document — so an empty or unprovisioned base simply never contributes, and it can never
 * make an otherwise-unavailable store chat available.
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
