<?php

declare(strict_types=1);

namespace App\Tests\Unit\KnowledgeBase;

use App\KnowledgeBase\Application\RuleService;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\Fake\ImmediateTransactionRunner;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryRuleRepository;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class RuleServiceTest extends Unit
{
    private const KB = 1;
    private const OTHER_KB = 2;

    private InMemoryRuleRepository $rules;
    private RuleService $service;

    protected function _before(): void
    {
        $this->rules = new InMemoryRuleRepository();
        $this->service = new RuleService($this->rules, new ImmediateTransactionRunner());
    }

    public function testAddAssignsIncreasingPriorities(): void
    {
        $this->service->add(self::KB, 'First', 'a', true);
        $this->service->add(self::KB, 'Second', 'b', true);

        $rules = $this->rules->findAllForKnowledgeBase(self::KB);
        assertSame(['First', 'Second'], array_map(static fn($r) => $r->name(), $rules));
        assertTrue($rules[0]->priority() < $rules[1]->priority(), 'later rules get higher priority');
    }

    public function testRejectsDuplicateNameWithinKnowledgeBase(): void
    {
        $this->service->add(self::KB, 'Only docs', 'a', true);

        $this->expectException(ValidationException::class);

        $this->service->add(self::KB, 'Only docs', 'b', true);
    }

    public function testSameNameAllowedInDifferentKnowledgeBases(): void
    {
        $this->service->add(self::KB, 'Only docs', 'a', true);
        $id = $this->service->add(self::OTHER_KB, 'Only docs', 'a', true);

        assertSame(1, $this->rules->countForKnowledgeBase(self::OTHER_KB));
        assertSame(self::OTHER_KB, $this->rules->findByIdForKnowledgeBase($id, self::OTHER_KB)?->knowledgeBaseId());
    }

    public function testRejectsEmptyNameAndInstruction(): void
    {
        try {
            $this->service->add(self::KB, '', '', true);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            assertSame(['name', 'instruction'], array_keys($e->errors()));
        }
    }

    public function testUpdateChangesNameAndInstruction(): void
    {
        $id = $this->service->add(self::KB, 'Old', 'old text', true);

        $this->service->update(self::KB, $id, 'New', 'new text');

        $rule = $this->rules->findByIdForKnowledgeBase($id, self::KB);
        assertSame('New', $rule?->name());
        assertSame('new text', $rule?->instruction());
    }

    public function testUpdateAllowsKeepingItsOwnName(): void
    {
        $id = $this->service->add(self::KB, 'Keep', 'text', true);

        // Re-saving with the same name must not trip the duplicate check against itself.
        $this->service->update(self::KB, $id, 'Keep', 'changed');

        assertSame('changed', $this->rules->findByIdForKnowledgeBase($id, self::KB)?->instruction());
    }

    public function testToggleEnablesAndDisables(): void
    {
        $id = $this->service->add(self::KB, 'Rule', 'text', true);

        $this->service->toggle(self::KB, $id, false);
        assertFalse($this->rules->findByIdForKnowledgeBase($id, self::KB)?->isEnabled());

        $this->service->toggle(self::KB, $id, true);
        assertTrue($this->rules->findByIdForKnowledgeBase($id, self::KB)?->isEnabled());
    }

    public function testDeleteRemovesTheRule(): void
    {
        $id = $this->service->add(self::KB, 'Rule', 'text', true);

        $this->service->delete(self::KB, $id);

        assertSame(0, $this->rules->countForKnowledgeBase(self::KB));
    }

    public function testReorderRenumbersToMatchTheGivenSequence(): void
    {
        $a = $this->service->add(self::KB, 'A', 'x', true);
        $b = $this->service->add(self::KB, 'B', 'x', true);
        $c = $this->service->add(self::KB, 'C', 'x', true);

        $this->service->reorder(self::KB, [$c, $a, $b]);

        $ordered = array_map(static fn($r) => $r->name(), $this->rules->findAllForKnowledgeBase(self::KB));
        assertSame(['C', 'A', 'B'], $ordered);
    }

    /**
     * A reorder payload must not be able to touch a rule in another knowledge base.
     */
    public function testReorderIgnoresForeignRuleIds(): void
    {
        $mine = $this->service->add(self::KB, 'Mine', 'x', true);
        $foreign = $this->service->add(self::OTHER_KB, 'Foreign', 'x', true);
        $foreignPriorityBefore = $this->rules->findByIdForKnowledgeBase($foreign, self::OTHER_KB)?->priority();

        $this->service->reorder(self::KB, [$foreign, $mine]);

        $foreignPriorityAfter = $this->rules->findByIdForKnowledgeBase($foreign, self::OTHER_KB)?->priority();
        assertSame($foreignPriorityBefore, $foreignPriorityAfter, 'a foreign rule must be untouched');
    }

    public function testOperatingOnAForeignRuleThrowsNotFound(): void
    {
        $foreign = $this->service->add(self::OTHER_KB, 'Foreign', 'x', true);

        $this->expectException(NotFoundException::class);

        // Attempting to toggle another knowledge base's rule through this base must 404, not succeed.
        $this->service->toggle(self::KB, $foreign, false);
    }

    public function testNormalizeOrderKeepsOnlyIntegerIds(): void
    {
        assertSame([3, 1, 2], RuleService::normalizeOrder(['3', 1, 'x', '2', null]));
        assertSame([], RuleService::normalizeOrder('not-an-array'));
    }
}
