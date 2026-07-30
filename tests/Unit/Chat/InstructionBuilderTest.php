<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Application\Instruction\ImmutableSecurityInstructions;
use App\Chat\Application\Instruction\InstructionBuilder;
use App\KnowledgeBase\Domain\Rule;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function mb_strpos;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;
use function strpos;
use function PHPUnit\Framework\assertTrue;

/**
 * The instruction string always leads with the immutable security block and reasserts it at the end,
 * with the KB's rules numbered in the order given.
 */
final class InstructionBuilderTest extends Unit
{
    private const FALLBACK = 'Sorry, I do not know.';

    public function testImmutableBlockIsFirstAndReminderIsLast(): void
    {
        $built = $this->builder()->build('Speak plainly.', $this->rules(), self::FALLBACK);

        $immutablePos = mb_strpos($built, '[IMMUTABLE');
        $reminderPos = mb_strpos($built, '[reminder]');
        $instructionsPos = mb_strpos($built, 'Speak plainly.');

        assertTrue($immutablePos === 0);
        assertGreaterThan((int) $instructionsPos, (int) $reminderPos); // reminder comes after everything
    }

    public function testFallbackSentenceIsEmbedded(): void
    {
        $built = $this->builder()->build(null, [], self::FALLBACK);

        assertStringContainsString(self::FALLBACK, $built);
    }

    public function testRulesAreNumberedInOrder(): void
    {
        $built = $this->builder()->build(null, $this->rules(), self::FALLBACK);

        assertStringContainsString('1. Answer only from documents', $built);
        assertStringContainsString('2. Use simple English', $built);
        // First rule appears before the second.
        assertGreaterThan(
            (int) mb_strpos($built, '1. Answer only from documents'),
            (int) mb_strpos($built, '2. Use simple English'),
        );
    }

    /**
     * @return list<Rule>
     */
    private function rules(): array
    {
        $now = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));

        return [
            new Rule(1, 7, 'A', 'Answer only from documents', 10, true, $now, $now),
            new Rule(2, 7, 'B', 'Use simple English', 20, true, $now, $now),
        ];
    }

    private function builder(): InstructionBuilder
    {
        return new InstructionBuilder(new ImmutableSecurityInstructions());
    }

    /**
     * The exhaustive directive is added only when asked for, and never outranks the immutable block —
     * the security reminder still comes last.
     */
    public function testExhaustiveDirectiveIsAppendedOnlyWhenRequested(): void
    {
        $builder = new InstructionBuilder(new ImmutableSecurityInstructions());

        $plain = $builder->build(null, [], 'fallback');
        $exhaustive = $builder->build(null, [], 'fallback', true);

        assertStringNotContainsString('[exhaustive answer]', $plain);
        assertStringContainsString('[exhaustive answer]', $exhaustive);
        // Generic by construction: the directive names no knowledge base, document or subject.
        assertStringNotContainsString('Moon Temple', $exhaustive);
        // The immutable reminder stays last so it still wins over the directive.
        assertGreaterThan(
            strpos($exhaustive, '[exhaustive answer]'),
            strpos($exhaustive, (new ImmutableSecurityInstructions())->reminder()),
        );
    }
}
