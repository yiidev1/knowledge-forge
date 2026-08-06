<?php

declare(strict_types=1);

namespace App\Rules\Application;

use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;
use RuntimeException;
use Yiisoft\Db\Exception\IntegrityException;

/**
 * Idempotently provides the single hidden, system-managed "Common Rules" knowledge base.
 *
 * It is identified by a stable slug (never a fixed numeric id), carries `purpose = shared_rules`,
 * `source_system = order58_rules`, `agent_enabled = 0` and no store source — so it is excluded from the admin
 * store directory (source_system), the agent store directory (agent_enabled) and the admin KB directory
 * (purpose), yet still provisions its vector store through the normal drainer and is reachable to internal
 * services (indexing, cleanup, usage, and the Phase 4 common-rules fallback).
 *
 * It is created lazily — only when the first common rule is confirmed — so no empty hidden base exists until it
 * is actually needed.
 */
final readonly class EnsureCommonRulesKnowledgeBaseService
{
    public const SLUG = 'order58-common-rules';
    public const NAME = 'Order58 Common Rules';
    public const PURPOSE = 'shared_rules';
    public const SOURCE_SYSTEM = 'order58_rules';

    public function __construct(
        private KnowledgeBaseRepositoryInterface $knowledgeBases,
    ) {}

    /**
     * Returns the hidden base's id, creating it if necessary.
     */
    public function ensure(): int
    {
        $existing = $this->knowledgeBases->findBySlug(self::SLUG);
        if ($existing !== null) {
            return $existing->id();
        }

        try {
            return $this->knowledgeBases->createSystem(self::NAME, self::SLUG, self::PURPOSE, self::SOURCE_SYSTEM);
        } catch (IntegrityException) {
            // A concurrent run created it first (the slug is unique) — adopt that row.
            $adopted = $this->knowledgeBases->findBySlug(self::SLUG);
            if ($adopted === null) {
                throw new RuntimeException('Common Rules knowledge base could not be created or adopted.');
            }

            return $adopted->id();
        }
    }

    /**
     * The hidden base if it already exists, without creating it (used for retirement and chat readiness).
     */
    public function find(): ?KnowledgeBase
    {
        return $this->knowledgeBases->findBySlug(self::SLUG);
    }
}
