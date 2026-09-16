<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Transcription;

use function count;
use function in_array;
use function preg_split;
use function sprintf;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * The keyterms handed to Deepgram's Keyterm Prompting, sanitised once.
 *
 * Keyterm Prompting biases recognition toward terms the model would otherwise mishear — "wonton"
 * rather than "one ton". The terms themselves are configuration today; a later feature will derive
 * them from repeated manual corrections, and will fill this same object.
 *
 * ## Two limits, and only one of them is ours to enforce
 *
 * Deepgram documents **at most 100 keyterms** and a **500-token budget across all of them**. Those are
 * not equally knowable from here:
 *
 *  - The **count is exact**. It is a property of the list, so it is enforced: 101 terms is a
 *    configuration error, reported before a request is built.
 *  - The **token budget is not**. We have no officially compatible tokenizer, and a whitespace word
 *    count is not a token count — subword tokenizers generally emit *more* tokens than words, so the
 *    estimate is neither exact nor reliably conservative in either direction. Rejecting a valid
 *    configuration because a rough counter disagreed with it would be worse than sending the request.
 *
 * So {@see estimatedTokens()} is advisory only, surfaced as a startup warning, and **Deepgram's own
 * HTTP 400 is the authoritative validation** of the token budget. A rejected request fails that one
 * job safely, with the provider's own message in the log.
 */
final readonly class DeepgramKeyterms
{
    /** Deepgram's documented ceiling on the number of keyterms in one request. Exactly countable. */
    public const MAX_TERMS = 100;

    /**
     * Deepgram's documented token budget. Used ONLY to phrase an advisory warning — never to refuse a
     * request. See the class docblock for why this is not treated as enforceable.
     */
    public const ADVISORY_TOKEN_BUDGET = 500;

    /**
     * @param list<string> $terms already trimmed, non-empty and de-duplicated
     */
    private function __construct(public array $terms) {}

    /**
     * @param list<string> $raw as configured, in any state
     */
    public static function fromList(array $raw): self
    {
        $clean = [];

        foreach ($raw as $term) {
            $trimmed = trim($term);

            // Empties come from a trailing comma or a double separator and would send `keyterm=`,
            // which is noise at best. Duplicates waste the budget without changing the outcome.
            if ($trimmed === '' || in_array($trimmed, $clean, true)) {
                continue;
            }

            $clean[] = $trimmed;
        }

        return new self($clean);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }

    public function count(): int
    {
        return count($this->terms);
    }

    /**
     * A rough word count across every term. Advisory — see the class docblock.
     */
    public function estimatedTokens(): int
    {
        $words = 0;

        foreach ($this->terms as $term) {
            $parts = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY);
            $words += $parts === false ? 1 : count($parts);
        }

        return $words;
    }

    /**
     * The one limit we can check exactly.
     *
     * @return string|null the problem, or null when the list is acceptable
     */
    public function countProblem(): ?string
    {
        if ($this->count() <= self::MAX_TERMS) {
            return null;
        }

        return sprintf(
            'DEEPGRAM_KEYTERMS lists %d terms. Deepgram accepts at most %d in one request, so the '
                . 'request would be rejected. Keep the most important terms.',
            $this->count(),
            self::MAX_TERMS,
        );
    }

    /**
     * An estimate worth mentioning, never a reason to refuse.
     *
     * @return string|null the warning, or null when the estimate is comfortably inside the budget
     */
    public function budgetWarning(): ?string
    {
        $estimate = $this->estimatedTokens();

        if ($estimate <= self::ADVISORY_TOKEN_BUDGET) {
            return null;
        }

        return sprintf(
            'DEEPGRAM_KEYTERMS is roughly %d words, above Deepgram\'s %d-token budget. This is an '
                . 'estimate, not a token count — Deepgram decides, and will answer 400 if the request '
                . 'really is too long. Transcription is not blocked.',
            $estimate,
            self::ADVISORY_TOKEN_BUDGET,
        );
    }

    /**
     * Query pairs, in order, one per term.
     *
     * Deliberately pairs rather than an associative array: Deepgram expects the key **repeated**
     * (`keyterm=a&keyterm=b`), and every array-shaped representation in PHP loses that — an
     * associative array keeps only the last value, and `http_build_query()` emits `keyterm[0]=`.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function toQueryPairs(): array
    {
        $pairs = [];

        foreach ($this->terms as $term) {
            $pairs[] = ['keyterm', $term];
        }

        return $pairs;
    }
}
