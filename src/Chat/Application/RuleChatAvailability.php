<?php

declare(strict_types=1);

namespace App\Chat\Application;

use App\Chat\Domain\Exception\ChatUnavailable;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\Rules\Application\CommonRulesReadiness;

/**
 * Whether the dedicated Rule Chat surface can answer. Deliberately separate from
 * {@see ChatAvailabilityPolicy} (Store Chat): Rule Chat needs a ready hidden rules KB with at least one
 * usable indexed global-rule document — not store-qualifying knowledge.
 */
final readonly class RuleChatAvailability
{
    public const UNAVAILABLE_MESSAGE = 'Rule chat is unavailable because no indexed rules are currently available.';

    public function __construct(
        private CommonRulesReadiness $readiness,
    ) {}

    public function isAvailable(): bool
    {
        return $this->readyKnowledgeBase() !== null;
    }

    public function readyKnowledgeBase(): ?KnowledgeBase
    {
        return $this->readiness->readyKnowledgeBase();
    }

    public function unavailableMessage(): string
    {
        return self::UNAVAILABLE_MESSAGE;
    }

    /**
     * @throws ChatUnavailable when Rule Chat cannot answer.
     */
    public function assertAvailable(): KnowledgeBase
    {
        $kb = $this->readyKnowledgeBase();
        if ($kb === null) {
            throw ChatUnavailable::noIndexedRules();
        }

        return $kb;
    }
}
