<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * The deterministic identity of a rule's content, derived from its normalized title + description.
 *
 * `canonicalHash` is the exact-duplicate identity (SHA-256 of normalized title + "\0" + normalized description):
 * two rules collapse into one canonical rule only when BOTH match, so multiple rules sharing a title but with
 * different bodies stay separate. `descriptionHash` groups *possible* duplicates (same description, different
 * title) for review only — for an empty description it falls back to `canonicalHash` so empty-bodied rules never
 * group together. `content` is the canonical body: the normalized description, or the normalized title when the
 * description is empty.
 */
final readonly class RuleIdentity
{
    public function __construct(
        public string $canonicalHash,
        public string $descriptionHash,
        public string $content,
    ) {}
}
