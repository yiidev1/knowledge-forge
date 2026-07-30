<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\KnowledgeBase;

use App\KnowledgeBase\Domain\Rule;
use App\KnowledgeBase\Domain\RuleRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;

use function array_values;
use function usort;

/**
 * In-memory rule repository for unit tests, with no database.
 */
final class InMemoryRuleRepository implements RuleRepositoryInterface
{
    /** @var array<int, Rule> */
    private array $items = [];

    private int $nextId = 1;

    public function findByIdForKnowledgeBase(int $ruleId, int $knowledgeBaseId): ?Rule
    {
        $rule = $this->items[$ruleId] ?? null;

        return $rule !== null && $rule->knowledgeBaseId() === $knowledgeBaseId ? $rule : null;
    }

    public function findAllForKnowledgeBase(int $knowledgeBaseId): array
    {
        return $this->ordered(fn(Rule $r): bool => $r->knowledgeBaseId() === $knowledgeBaseId);
    }

    public function findEnabledForKnowledgeBase(int $knowledgeBaseId): array
    {
        return $this->ordered(fn(Rule $r): bool => $r->knowledgeBaseId() === $knowledgeBaseId && $r->isEnabled());
    }

    public function nameExistsInKnowledgeBase(string $name, int $knowledgeBaseId, ?int $excludingRuleId = null): bool
    {
        foreach ($this->items as $rule) {
            if ($rule->knowledgeBaseId() === $knowledgeBaseId
                && $rule->name() === $name
                && $rule->id() !== $excludingRuleId) {
                return true;
            }
        }

        return false;
    }

    public function countForKnowledgeBase(int $knowledgeBaseId): int
    {
        return count($this->findAllForKnowledgeBase($knowledgeBaseId));
    }

    public function countsByKnowledgeBase(): array
    {
        $counts = [];
        foreach ($this->items as $rule) {
            $kbId = $rule->knowledgeBaseId();
            $counts[$kbId] ??= ['total' => 0, 'enabled' => 0];
            $counts[$kbId]['total']++;
            if ($rule->isEnabled()) {
                $counts[$kbId]['enabled']++;
            }
        }

        return $counts;
    }

    public function maxPriority(int $knowledgeBaseId): ?int
    {
        $max = null;
        foreach ($this->findAllForKnowledgeBase($knowledgeBaseId) as $rule) {
            $max = $max === null ? $rule->priority() : max($max, $rule->priority());
        }

        return $max;
    }

    public function create(int $knowledgeBaseId, string $name, string $instruction, int $priority, bool $isEnabled): int
    {
        $id = $this->nextId++;
        $this->items[$id] = $this->make($id, $knowledgeBaseId, $name, $instruction, $priority, $isEnabled);

        return $id;
    }

    public function update(int $ruleId, string $name, string $instruction): void
    {
        $r = $this->items[$ruleId] ?? null;
        if ($r !== null) {
            $this->items[$ruleId] = $this->make($ruleId, $r->knowledgeBaseId(), $name, $instruction, $r->priority(), $r->isEnabled());
        }
    }

    public function setEnabled(int $ruleId, bool $isEnabled): void
    {
        $r = $this->items[$ruleId] ?? null;
        if ($r !== null) {
            $this->items[$ruleId] = $this->make($ruleId, $r->knowledgeBaseId(), $r->name(), $r->instruction(), $r->priority(), $isEnabled);
        }
    }

    public function setPriority(int $ruleId, int $priority): void
    {
        $r = $this->items[$ruleId] ?? null;
        if ($r !== null) {
            $this->items[$ruleId] = $this->make($ruleId, $r->knowledgeBaseId(), $r->name(), $r->instruction(), $priority, $r->isEnabled());
        }
    }

    public function delete(int $ruleId): void
    {
        unset($this->items[$ruleId]);
    }

    /**
     * @param callable(Rule): bool $filter
     *
     * @return list<Rule>
     */
    private function ordered(callable $filter): array
    {
        $result = [];
        foreach ($this->items as $rule) {
            if ($filter($rule)) {
                $result[] = $rule;
            }
        }

        usort($result, static fn(Rule $a, Rule $b): int => [$a->priority(), $a->id()] <=> [$b->priority(), $b->id()]);

        return array_values($result);
    }

    private function make(int $id, int $kbId, string $name, string $instruction, int $priority, bool $isEnabled): Rule
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        return new Rule($id, $kbId, $name, $instruction, $priority, $isEnabled, $now, $now);
    }
}
