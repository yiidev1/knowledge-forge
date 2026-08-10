<?php

declare(strict_types=1);

namespace App\Chat\Application;

use App\KnowledgeBase\Domain\KnowledgeBase;
use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;

/**
 * Resolves the hidden global-rules knowledge base for dedicated Rule Chat.
 *
 * Does not use {@see \App\Agent\Web\Chat\AgentStoreResolver} — that directory deliberately excludes
 * system/shared-rules bases. Discovery is by slug via {@see EnsureCommonRulesKnowledgeBaseService}.
 */
final readonly class RuleChatKnowledgeBaseResolver
{
    public function __construct(
        private EnsureCommonRulesKnowledgeBaseService $globalBase,
        private RuleChatAvailability $availability,
    ) {}

    /**
     * The rules KB if it exists (even when not yet ready for chat). Null when never ensured/created.
     */
    public function find(): ?KnowledgeBase
    {
        return $this->globalBase->find();
    }

    /**
     * The ready rules KB, or throws {@see \App\Chat\Domain\Exception\ChatUnavailable}.
     */
    public function requireReady(): KnowledgeBase
    {
        return $this->availability->assertAvailable();
    }

    public function isAvailable(): bool
    {
        return $this->availability->isAvailable();
    }

    public function unavailableMessage(): string
    {
        return $this->availability->unavailableMessage();
    }
}
