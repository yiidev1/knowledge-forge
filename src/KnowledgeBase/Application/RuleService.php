<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Application;

use App\KnowledgeBase\Domain\Exception\RuleNotFound;
use App\KnowledgeBase\Domain\Rule;
use App\KnowledgeBase\Domain\RuleRepositoryInterface;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Shared\Domain\Exception\ValidationException;

use function mb_strlen;
use function trim;

/**
 * Manages the lifecycle of a knowledge base's rules: add, edit, enable/disable, reorder, delete.
 *
 * One cohesive service rather than five near-identical ones, because these operations share the same
 * invariants — every rule is scoped to its knowledge base, and names are unique within it. Priorities
 * are assigned in gaps of ten so a rule can later be slotted between two others; a full reorder
 * renumbers them and runs in a transaction so the sequence is never left half-applied.
 */
final readonly class RuleService
{
    private const PRIORITY_STEP = 10;
    private const NAME_MAX = 160;
    private const INSTRUCTION_MAX = 5000;

    public function __construct(
        private RuleRepositoryInterface $rules,
        private TransactionRunnerInterface $transaction,
    ) {}

    /**
     * @return int The id of the created rule.
     *
     * @throws ValidationException on empty/over-long input or a duplicate name.
     */
    public function add(int $knowledgeBaseId, string $name, string $instruction, bool $isEnabled): int
    {
        $name = trim($name);
        $instruction = trim($instruction);
        $this->validate($knowledgeBaseId, $name, $instruction, null);

        // Append after the current last rule, leaving the earlier gaps intact.
        $priority = ($this->rules->maxPriority($knowledgeBaseId) ?? 0) + self::PRIORITY_STEP;

        return $this->rules->create($knowledgeBaseId, $name, $instruction, $priority, $isEnabled);
    }

    /**
     * @throws ValidationException on empty/over-long input or a duplicate name.
     */
    public function update(int $knowledgeBaseId, int $ruleId, string $name, string $instruction): void
    {
        $rule = $this->requireRule($knowledgeBaseId, $ruleId);

        $name = trim($name);
        $instruction = trim($instruction);
        $this->validate($knowledgeBaseId, $name, $instruction, $rule->id());

        $this->rules->update($rule->id(), $name, $instruction);
    }

    public function toggle(int $knowledgeBaseId, int $ruleId, bool $isEnabled): void
    {
        $rule = $this->requireRule($knowledgeBaseId, $ruleId);
        $this->rules->setEnabled($rule->id(), $isEnabled);
    }

    public function delete(int $knowledgeBaseId, int $ruleId): void
    {
        $rule = $this->requireRule($knowledgeBaseId, $ruleId);
        $this->rules->delete($rule->id());
    }

    /**
     * Renumbers rules to match the given order. Ids not belonging to the knowledge base are ignored, so
     * a tampered form cannot reprioritise another knowledge base's rules.
     *
     * @param list<int> $orderedRuleIds
     */
    public function reorder(int $knowledgeBaseId, array $orderedRuleIds): void
    {
        $owned = [];
        foreach ($this->rules->findAllForKnowledgeBase($knowledgeBaseId) as $rule) {
            $owned[$rule->id()] = true;
        }

        $this->transaction->run(function () use ($orderedRuleIds, $owned): void {
            $priority = self::PRIORITY_STEP;
            foreach ($orderedRuleIds as $ruleId) {
                if (isset($owned[$ruleId])) {
                    $this->rules->setPriority($ruleId, $priority);
                    $priority += self::PRIORITY_STEP;
                }
            }
        });
    }

    private function requireRule(int $knowledgeBaseId, int $ruleId): Rule
    {
        $rule = $this->rules->findByIdForKnowledgeBase($ruleId, $knowledgeBaseId);

        if (!$rule instanceof Rule) {
            throw RuleNotFound::inKnowledgeBase($ruleId, $knowledgeBaseId);
        }

        return $rule;
    }

    /**
     * @throws ValidationException
     */
    private function validate(int $knowledgeBaseId, string $name, string $instruction, ?int $excludingRuleId): void
    {
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Enter a rule name.';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = sprintf('Rule name must be at most %d characters.', self::NAME_MAX);
        } elseif ($this->rules->nameExistsInKnowledgeBase($name, $knowledgeBaseId, $excludingRuleId)) {
            $errors['name'] = 'A rule with this name already exists in this knowledge base.';
        }

        if ($instruction === '') {
            $errors['instruction'] = 'Enter the rule instruction.';
        } elseif (mb_strlen($instruction) > self::INSTRUCTION_MAX) {
            $errors['instruction'] = sprintf('Instruction must be at most %d characters.', self::INSTRUCTION_MAX);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * @return list<int>
     */
    public static function normalizeOrder(mixed $rawOrder): array
    {
        if (!is_array($rawOrder)) {
            return [];
        }

        $ids = [];
        foreach ($rawOrder as $value) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}
