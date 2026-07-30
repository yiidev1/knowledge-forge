<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Application\Retrieval\ExhaustiveIntentDetector;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

/**
 * Questions asking for every match are recognised; questions asking for one are not.
 *
 * The distinction drives a wider search and a different instruction, so a false positive is cheap but a
 * false negative reintroduces the original bug. The detector must also stay subject-agnostic: nothing
 * here mentions a knowledge base, document or business term, because the same detector serves them all.
 */
final class ExhaustiveIntentDetectorTest extends Unit
{
    private ExhaustiveIntentDetector $detector;

    protected function _before(): void
    {
        $this->detector = new ExhaustiveIntentDetector();
    }

    /**
     * The two questions that regressed in production, plus the phrasings around them.
     */
    public function testRecognisesExhaustiveQuestions(): void
    {
        $questions = [
            'List the exact titles of all records whose title contains Moon Temple. Include citations.',
            'Show the complete details of every record whose title contains Moon Temple.',
            'List each record separately and include all available fields.',
            'Give me the full list of policies.',
            'What are all the deadlines?',
            'Show every matching entry.',
            'I need an exhaustive summary.',
            'Show the entire set of rules.',
            'List all records.',
        ];

        foreach ($questions as $question) {
            assertTrue($this->detector->isExhaustive($question), $question);
        }
    }

    public function testLeavesNarrowQuestionsAlone(): void
    {
        $questions = [
            'Search the uploaded PDF and return one record whose title contains Moon Temple.',
            'Who is the emergency coordinator?',
            'Where is the first-aid room?',
            'What is the refund window?',
        ];

        foreach ($questions as $question) {
            assertFalse($this->detector->isExhaustive($question), $question);
        }
    }

    /**
     * Word boundaries matter: without them "all" matches inside "small", "wallet" and "finally", and
     * essentially every question would be treated as exhaustive.
     */
    public function testDoesNotMatchQuantifiersInsideOtherWords(): void
    {
        $questions = [
            'What is the smallest allowance?',
            'Where is my wallet policy?',
            'What happened finally?',
            'Is there an alleyway entrance?',
            'Describe the cache eviction.',
        ];

        foreach ($questions as $question) {
            assertFalse($this->detector->isExhaustive($question), $question);
        }
    }

    public function testIsCaseInsensitive(): void
    {
        assertTrue($this->detector->isExhaustive('LIST ALL RECORDS'));
        assertTrue($this->detector->isExhaustive('Every Record, Please'));
    }
}
