<?php

declare(strict_types=1);

namespace App\Rules\Application;

use App\Rules\Domain\ApprovedAlias;
use App\Rules\Domain\ClassificationResult;
use App\Rules\Domain\ClassificationStatus;
use App\Rules\Domain\RuleScope;
use App\Rules\Domain\StoreLinkCandidate;
use App\Rules\Domain\StoreMatchMethod;
use App\Rules\Domain\StoreMatchStatus;

use function array_key_first;
use function array_values;
use function count;
use function in_array;
use function mb_strtolower;
use function preg_match;
use function str_contains;
use function str_replace;
use function strlen;
use function trim;

/**
 * Deterministic store detection for a canonical rule, in strict priority order:
 *
 *   1. `source_store_id` (a future authoritative id) — highest confidence, outranks everything.
 *   2. exact domain alias in the title or description.
 *   3. exact normalized alias as a whole word in the title.
 *   4. exact normalized alias as a whole word in the description (lower confidence).
 *   5. fuzzy near-match — a *suggestion* only, never auto-confirmed.
 *
 * Exactly one exact store → auto-matched with a **confirmed** deterministic link (the matched store gets the
 * rule at stage-1; every other store still reaches it via the stage-2 global fallback). Several exact stores →
 * ambiguous (suggested links, no store projection). An apparent-but-unknown store name → unmatched (no store
 * projection, still globally available). A pure fuzzy near-match → a suggested link, left pending. Otherwise —
 * no store candidate at all → **auto-confirmed common** (`confirmed_common`), so a store-agnostic rule is
 * searchable everywhere without manual confirmation. Classification is a reporting concern; global availability
 * is a separate flag defaulting to on for every active rule.
 *
 * This class is pure (no I/O) so the whole policy is unit-testable.
 */
final class RuleStoreMatcher
{
    /** Method authority, high → low, used to keep the best exact candidate per store. */
    private const METHOD_RANK = [
        'domain' => 3,
        'title_exact_alias' => 2,
        'description_exact_alias' => 1,
    ];

    /** Phrases that *suggest* (never confirm) a rule is a general/common rule. */
    private const GENERAL_PHRASES = [
        'all restaurants', 'all stores', 'any store', 'every store', 'for all stores', 'all agents',
        'to all agents', 'for any store', 'general order', 'general callback', 'general delivery',
        'general procedure', 'all locations', 'every location',
    ];

    private const FUZZY_MIN_LENGTH = 5;

    /**
     * @param list<int>           $knownStoreIds Store source ids that exist in the local mirror.
     * @param list<ApprovedAlias> $aliases       Approved aliases across all stores.
     */
    public function match(?int $sourceStoreId, array $knownStoreIds, string $title, string $description, array $aliases): ClassificationResult
    {
        // Priority 1: authoritative source store id → a confirmed deterministic match (an admin can still
        // reject or change it later; the reviewed link is never overwritten by a later sync).
        if ($sourceStoreId !== null && in_array($sourceStoreId, $knownStoreIds, true)) {
            return new ClassificationResult(
                RuleScope::StoreSpecific,
                ClassificationStatus::AutoMatched,
                [new StoreLinkCandidate($sourceStoreId, StoreMatchMethod::SourceStoreId, StoreMatchStatus::Confirmed, 1.0, null)],
                null,
                'matched by authoritative source_store_id',
            );
        }

        $titleTokens = ' ' . AliasNormalizer::normalize($title) . ' ';
        $descTokens = ' ' . AliasNormalizer::normalize($description) . ' ';
        $titleLower = mb_strtolower($title);
        $descLower = mb_strtolower($description);

        /** @var array<int, array{method: StoreMatchMethod, rank: int, text: string, confidence: float}> $exact */
        $exact = [];
        /** @var array<int, StoreLinkCandidate> $fuzzy */
        $fuzzy = [];

        foreach ($aliases as $alias) {
            if ($alias->normalized === '') {
                continue;
            }

            if ($alias->type->value === 'domain') {
                if (str_contains($titleLower, $alias->normalized) || str_contains($descLower, $alias->normalized)) {
                    $this->recordExact($exact, $alias->storeSourceId, StoreMatchMethod::Domain, 0.98, $alias->alias);
                }
                continue;
            }

            $needle = ' ' . $alias->normalized . ' ';
            if (str_contains($titleTokens, $needle)) {
                $this->recordExact($exact, $alias->storeSourceId, StoreMatchMethod::TitleExactAlias, 0.9, $alias->alias);
            } elseif (str_contains($descTokens, $needle)) {
                $this->recordExact($exact, $alias->storeSourceId, StoreMatchMethod::DescriptionExactAlias, 0.75, $alias->alias);
            } else {
                // Fuzzy: the alias with spaces removed appears run-together in the title (e.g. "Moontemple").
                $aliasNoSpace = str_replace(' ', '', $alias->normalized);
                $titleNoSpace = str_replace(' ', '', $titleTokens);
                if (strlen($aliasNoSpace) >= self::FUZZY_MIN_LENGTH && str_contains($titleNoSpace, $aliasNoSpace)) {
                    $fuzzy[$alias->storeSourceId] ??= new StoreLinkCandidate(
                        $alias->storeSourceId,
                        StoreMatchMethod::FuzzySuggestion,
                        StoreMatchStatus::Suggested,
                        0.5,
                        $alias->alias,
                    );
                }
            }
        }

        if (count($exact) === 1) {
            $storeId = array_key_first($exact);
            $best = $exact[$storeId];

            return new ClassificationResult(
                RuleScope::StoreSpecific,
                ClassificationStatus::AutoMatched,
                [new StoreLinkCandidate($storeId, $best['method'], StoreMatchStatus::Confirmed, $best['confidence'], $best['text'])],
                null,
                'matched a single store by ' . $best['method']->value,
            );
        }

        if (count($exact) > 1) {
            $candidates = [];
            foreach ($exact as $storeId => $best) {
                $candidates[] = new StoreLinkCandidate($storeId, $best['method'], StoreMatchStatus::Suggested, $best['confidence'], $best['text']);
            }

            return new ClassificationResult(RuleScope::Unresolved, ClassificationStatus::Ambiguous, $candidates, null, 'matched more than one store');
        }

        if ($fuzzy !== []) {
            return new ClassificationResult(
                RuleScope::Unresolved,
                ClassificationStatus::Pending,
                array_values($fuzzy),
                null,
                'a fuzzy store suggestion is awaiting review',
            );
        }

        // No store matched. Is there an apparent store reference we could not resolve? Keep it `unmatched` for
        // reporting (no store projection) — but it is still globally available via the default flag.
        $apparent = $this->detectApparentStore($title);
        if ($apparent !== null) {
            return new ClassificationResult(RuleScope::Unresolved, ClassificationStatus::Unmatched, [], $apparent, 'names a store not found in the mirror');
        }

        // No store candidate at all → auto-confirm as a common rule so it is searchable everywhere without any
        // manual step (a general-language phrase gets a clearer reason, but both auto-confirm).
        if ($this->looksGeneral($titleTokens . $descTokens)) {
            return new ClassificationResult(RuleScope::Common, ClassificationStatus::ConfirmedCommon, [], null, 'auto_confirmed_common_general_language');
        }

        return new ClassificationResult(RuleScope::Common, ClassificationStatus::ConfirmedCommon, [], null, 'auto_confirmed_common_no_store_match');
    }

    /**
     * @param array<int, array{method: StoreMatchMethod, rank: int, text: string, confidence: float}> $exact
     */
    private function recordExact(array &$exact, int $storeId, StoreMatchMethod $method, float $confidence, string $text): void
    {
        $rank = self::METHOD_RANK[$method->value] ?? 0;
        if (!isset($exact[$storeId]) || $rank > $exact[$storeId]['rank']) {
            $exact[$storeId] = ['method' => $method, 'rank' => $rank, 'text' => $text, 'confidence' => $confidence];
        }
    }

    private function looksGeneral(string $normalizedText): bool
    {
        foreach (self::GENERAL_PHRASES as $phrase) {
            if (str_contains($normalizedText, ' ' . $phrase . ' ') || str_contains($normalizedText, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detects an apparent store name the matcher could not resolve — e.g. "For Golden Palace…" or
     * "Golden Palace:". Deliberately conservative: it only fires on these explicit lead-in shapes so an
     * ordinary rule is never mislabelled as a missing store.
     */
    private function detectApparentStore(string $title): ?string
    {
        // The "for" / "rule for" lead-in is case-insensitive; the captured store name must still start uppercase.
        if (preg_match('/^\s*(?i:(?:rule\s+)?for)\s+([A-Z][\w\'&.-]*(?:\s+[A-Z][\w\'&.-]*){0,3})/', $title, $m) === 1) {
            return trim($m[1]);
        }
        if (preg_match('/^\s*([A-Z][\w\'&.-]*(?:\s+[A-Z][\w\'&.-]*){0,3})\s*:/', $title, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }
}
