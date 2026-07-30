<?php

declare(strict_types=1);

namespace App\Chat\Application\Retrieval;

use function preg_match;

/**
 * Recognises questions that ask for *every* match rather than *a* match.
 *
 * The two need different retrieval. "Return one record whose title contains X" is satisfied by the single
 * best chunk a relevance search returns; "list the titles of all records containing X" is not — a search
 * tuned to return the top handful of chunks cannot enumerate a set, and the model then either answers
 * partially or declares it cannot answer at all.
 *
 * Detection is intentionally shallow and generic: a word-boundary match on quantifiers and enumeration
 * phrasing. It carries no knowledge of any particular knowledge base, document or subject — the same
 * detector serves every knowledge base, and adding a new one requires no change here. A false positive
 * costs a wider search and a slightly longer instruction; a false negative just leaves today's behaviour.
 */
final readonly class ExhaustiveIntentDetector
{
    /**
     * Quantifiers and enumeration phrasings, matched case-insensitively on word boundaries.
     *
     * `\b` matters: without it "small" would match "all" and almost every question would look exhaustive.
     */
    private const PATTERN = '/\b('
        . 'all|every|each|both|'
        . 'complete\s+(list|details|set)|full\s+(list|details|set)|'
        . 'list\s+(all|every|each|the\s+exact)|'
        . 'exact\s+titles|'
        . 'every\s+record|each\s+record|all\s+records|'
        . 'exhaustive|entire\s+(list|set)'
        . ')\b/i';

    public function isExhaustive(string $question): bool
    {
        return preg_match(self::PATTERN, $question) === 1;
    }
}
