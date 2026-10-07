<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

/**
 * The attempt now holding a source order, and whether this call is what created it.
 *
 * `created` is reported by the repository rather than inferred by the caller. The obvious inference —
 * compare `started_at` to the clock — is wrong in practice: the row is read back from MySQL at second
 * precision while the clock carries microseconds, so a freshly created attempt would sometimes look like
 * a resumed one and the operator would be told they were continuing something they had just begun.
 */
final readonly class AttemptStart
{
    public function __construct(
        public TestAttempt $attempt,
        public bool $created,
    ) {}
}
