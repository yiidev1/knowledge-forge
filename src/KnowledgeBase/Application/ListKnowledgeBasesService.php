<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Application;

use App\KnowledgeBase\Application\Dto\KnowledgeBaseSummary;
use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;
use App\KnowledgeBase\Domain\RuleRepositoryInterface;

/**
 * Builds the knowledge-base list with per-base rule counts, using a single grouped count query rather
 * than one query per row.
 */
final readonly class ListKnowledgeBasesService
{
    public function __construct(
        private KnowledgeBaseRepositoryInterface $knowledgeBases,
        private RuleRepositoryInterface $rules,
    ) {}

    /**
     * @return list<KnowledgeBaseSummary>
     */
    public function list(bool $includeArchived = false): array
    {
        $counts = $this->rules->countsByKnowledgeBase();

        $summaries = [];
        foreach ($this->knowledgeBases->findAll($includeArchived) as $knowledgeBase) {
            $count = $counts[$knowledgeBase->id()] ?? ['total' => 0, 'enabled' => 0];
            $summaries[] = new KnowledgeBaseSummary($knowledgeBase, $count['total'], $count['enabled']);
        }

        return $summaries;
    }
}
