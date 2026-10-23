<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Practice;

use App\OrderTesting\Domain\InitiatorType;
use DateTimeImmutable;

/**
 * One practitioner's row in the Practice Results table, as the database can answer it.
 *
 * ## What is here, and what deliberately is not
 *
 * Everything on this object comes from one indexed aggregate: who practised, how many results they
 * produced, and when they last did. No scores. A score is not stored anywhere — it is derived by
 * comparing a demo order's document against the original, in PHP — so asking the database for a best
 * or latest score is not a query that exists.
 *
 * Scores are attached afterwards, for the page's rows only and under a budget, by
 * {@see \App\OrderTesting\Application\Practice\PractitionerScorer}. Keeping them off this object is
 * what makes that separation impossible to forget: a caller that wants a score has to go somewhere
 * that knows what scoring costs.
 *
 * ## The realm travels with the id
 *
 * `admin_users.id = 171` and Order58's `admin_id = 171` are different people, and this installation
 * has a live collision. A practitioner is identified by the pair, never by the number.
 */
final readonly class PractitionerSummary
{
    public function __construct(
        public InitiatorType $realm,
        public int $actorId,
        /** Their username as the mirror holds it, or null when the account no longer exists. */
        public ?string $name,
        /** Imported demo orders attributed to this practitioner for this source order. */
        public int $completedTries,
        /** When the newest of those was created in Order58 — not when it was imported here. */
        public ?DateTimeImmutable $lastPractisedAt,
    ) {}

    /**
     * A stable key for this practitioner, for URLs and for keying a page's rows.
     *
     * The realm is part of it for the reason above: two realms number their users separately, so the
     * id alone would collapse two people into one row.
     */
    public function key(): string
    {
        return $this->realm->value . ':' . $this->actorId;
    }

    /**
     * What to call them on screen.
     *
     * A practitioner whose account has since been removed keeps their results and their place in the
     * table — the work happened — and is named by what is still known about them. Inventing a name
     * would be worse than admitting the account is gone.
     */
    public function displayName(): string
    {
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        return $this->realm->label() . ' #' . $this->actorId;
    }
}
