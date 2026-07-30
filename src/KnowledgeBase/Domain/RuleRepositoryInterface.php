<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Domain;

/**
 * Persistence boundary for knowledge-base rules.
 *
 * Every method is scoped by knowledge-base id: a rule is never addressed on its own, which is what
 * prevents a rule id from one knowledge base being used against another.
 */
interface RuleRepositoryInterface
{
    public function findByIdForKnowledgeBase(int $ruleId, int $knowledgeBaseId): ?Rule;

    /**
     * @return list<Rule> All rules for the knowledge base, enabled first is NOT assumed — ordered by
     *                    priority then id, which is the order the prompt builder applies them in.
     */
    public function findAllForKnowledgeBase(int $knowledgeBaseId): array;

    /**
     * @return list<Rule> Only enabled rules, in application order. Used when building a chat prompt.
     */
    public function findEnabledForKnowledgeBase(int $knowledgeBaseId): array;

    public function nameExistsInKnowledgeBase(string $name, int $knowledgeBaseId, ?int $excludingRuleId = null): bool;

    public function countForKnowledgeBase(int $knowledgeBaseId): int;

    /**
     * Rule counts for every knowledge base in one query, to avoid an N+1 on the list screen.
     *
     * @return array<int, array{total: int, enabled: int}> Keyed by knowledge-base id. A knowledge base
     *                                                      with no rules is simply absent from the map.
     */
    public function countsByKnowledgeBase(): array;

    /**
     * @return int The highest priority value currently in use, or null when the knowledge base has no
     *             rules yet, so a new rule can be appended after the last.
     */
    public function maxPriority(int $knowledgeBaseId): ?int;

    /**
     * @return int The id of the newly created rule.
     */
    public function create(int $knowledgeBaseId, string $name, string $instruction, int $priority, bool $isEnabled): int;

    public function update(int $ruleId, string $name, string $instruction): void;

    public function setEnabled(int $ruleId, bool $isEnabled): void;

    public function setPriority(int $ruleId, int $priority): void;

    public function delete(int $ruleId): void;
}
