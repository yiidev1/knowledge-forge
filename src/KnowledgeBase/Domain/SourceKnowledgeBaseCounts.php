<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Domain;

/**
 * Admin summary counts for source-backed (Order58) knowledge bases, kept deliberately separate from the
 * "active store" count. "Source active" (mirrors the store) and "agent enabled" (a local override) and
 * "ready" (vector store provisioned) are three different things, so the Data Management page can show them
 * side by side instead of conflating source-active with chat-ready.
 */
final readonly class SourceKnowledgeBaseCounts
{
    public function __construct(
        public int $total,
        public int $agentEnabled,
        public int $ready,
    ) {}
}
