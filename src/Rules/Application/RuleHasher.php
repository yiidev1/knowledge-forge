<?php

declare(strict_types=1);

namespace App\Rules\Application;

use App\Document\Application\Text\PlainTextNormalizer;
use App\Rules\Domain\RuleIdentity;

use function hash;

/**
 * Computes a rule's {@see RuleIdentity} from its raw title + description using the shared, deterministic
 * {@see PlainTextNormalizer} (BOM/CRLF/trim/collapse) so the same content always yields the same hash and
 * whitespace-only differences deduplicate.
 *
 * Identity is case-sensitive on purpose: case folding is NOT applied, to avoid merging rules that differ only
 * in case (which could be semantically distinct). Whitespace normalization alone drives whitespace dedupe.
 */
final class RuleHasher
{
    /** Separates the title and description segments so a title char can never masquerade as a description char. */
    private const SEPARATOR = "\0";

    public function identify(string $title, string $description): RuleIdentity
    {
        $normalizedTitle = PlainTextNormalizer::normalize($title);
        $normalizedDescription = PlainTextNormalizer::normalize($description);

        $canonicalHash = hash('sha256', $normalizedTitle . self::SEPARATOR . $normalizedDescription);

        // Empty description ⇒ fall back to the canonical hash so empty-bodied rules never group as possible
        // duplicates of one another; the title still fully determines identity above.
        $descriptionHash = $normalizedDescription === ''
            ? $canonicalHash
            : hash('sha256', $normalizedDescription);

        $content = $normalizedDescription !== '' ? $normalizedDescription : $normalizedTitle;

        return new RuleIdentity($canonicalHash, $descriptionHash, $content);
    }
}
