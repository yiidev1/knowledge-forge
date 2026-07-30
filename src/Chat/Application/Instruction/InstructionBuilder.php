<?php

declare(strict_types=1);

namespace App\Chat\Application\Instruction;

use App\KnowledgeBase\Domain\Rule;

use function count;
use function implode;
use function trim;

/**
 * Assembles the full instruction string sent to the model, in a fixed precedence:
 *
 *   1. the immutable security block,
 *   2. the knowledge base's own instructions (admin free text),
 *   3. the enabled rules, numbered in priority order,
 *   4. the immutable reminder, restating that (1) wins over everything above.
 *
 * Rules arrive already filtered to enabled and ordered by priority; the builder only renders them, so
 * the ordering policy lives in the repository and the security policy lives here — provider-neutral, a
 * plain string, so swapping the AI provider changes nothing.
 */
final readonly class InstructionBuilder
{
    public function __construct(
        private ImmutableSecurityInstructions $security,
    ) {}

    /**
     * Appended only for questions asking for every match. Generic on purpose — it names no knowledge
     * base, document, field or subject, so it applies unchanged to any content. It tells the model to
     * search more than once, because one relevance query returns the closest chunks rather than the
     * complete set, and to keep citing per item so the grounding check still has annotations to verify.
     */
    private const EXHAUSTIVE_DIRECTIVE = <<<'TEXT'
        [exhaustive answer]
        This question asks for every match, not just the best one.
        - Run SEVERAL file searches rather than one: repeat the search for meaningful variants of the key
          terms, including different capitalisation, spacing and joined or hyphenated spellings.
        - Merge the results and remove duplicates that refer to the same underlying item.
        - List every distinct match separately, preserving values exactly as they appear in the source.
        - Cite the source for each item. If the list is too long to finish, say so explicitly and state
          how many items you covered rather than silently truncating.
        TEXT;

    /**
     * @param list<Rule> $enabledRules Already enabled and ordered by priority (ASC), id (ASC).
     */
    public function build(
        ?string $knowledgeBaseInstructions,
        array $enabledRules,
        string $fallbackSentence,
        bool $exhaustive = false,
    ): string {
        $sections = [$this->security->header($fallbackSentence)];

        $instructions = trim((string) $knowledgeBaseInstructions);
        if ($instructions !== '') {
            $sections[] = "[knowledge base instructions]\n" . $instructions;
        }

        if (count($enabledRules) > 0) {
            $lines = ['[knowledge base rules]'];
            $n = 1;
            foreach ($enabledRules as $rule) {
                $lines[] = $n++ . '. ' . trim($rule->instruction());
            }
            $sections[] = implode("\n", $lines);
        }

        if ($exhaustive) {
            $sections[] = self::EXHAUSTIVE_DIRECTIVE;
        }

        // The reminder stays last so the immutable block still outranks everything added above it,
        // including the directive.
        $sections[] = $this->security->reminder();

        return implode("\n\n", $sections);
    }
}
