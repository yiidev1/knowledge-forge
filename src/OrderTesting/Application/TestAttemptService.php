<?php

declare(strict_types=1);

namespace App\OrderTesting\Application;

use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\TestAttemptRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;

use function sprintf;
use function strtolower;

/**
 * The one place a test attempt is started, and the one place the one-at-a-time rule is stated.
 *
 * ## Why only one attempt may be open per source order
 *
 * Order58's demo document carries no field this application controls. There is no token we can put into
 * the demo URL and read back out of the resulting JSON — the existing listener derives everything it
 * knows from `uid`, which Order58 composes itself from the source order, the phone and a timestamp. So
 * when a demo order arrives, the only thing tying it to a person is **which attempt was open for that
 * source order when it appeared**.
 *
 * That inference is sound exactly as long as one attempt is open at a time. Allow two and every demo
 * order for that source order becomes a coin toss between two operators, recorded as fact. So the second
 * person is refused, told who holds it and until when, and nothing ambiguous is written.
 *
 * This is a real limitation and it is documented rather than hidden: see the feature's final report. A
 * future Agent rollout, where several people genuinely do work the same orders at once, needs Order58 to
 * preserve and return a correlation identifier. Inventing one in the URL would not work — nothing proves
 * Order58 echoes it — and a guess that looks like attribution is worse than an honest blank.
 *
 * ## The window closes by itself
 *
 * An attempt expires. Without that, one operator who clicked and went to lunch would hold a source order
 * shut for everyone, forever, and the only cure would be a database edit.
 */
final readonly class TestAttemptService
{
    public function __construct(
        private TestAttemptRepositoryInterface $attempts,
        private ClockInterface $clock,
        /** How long an attempt holds its source order. Configuration, never request data. */
        private int $ttlMinutes,
    ) {}

    public function start(
        int $storeSourceId,
        int $sourceOrderId,
        InitiatorType $type,
        int $initiatedById,
    ): StartAttemptOutcome {
        $now = $this->clock->now();

        // Opportunistic, and the reason a forgotten click cannot lock a row forever. Cheap: an indexed
        // UPDATE over rows that are almost always none.
        $this->attempts->expireOverdue($now);

        $expiresAt = $now->modify(sprintf('+%d minutes', $this->ttlMinutes));

        // Conditional insert. Of two simultaneous clicks exactly one creates a row; the other is handed
        // the winner's, and finds out below whose it is.
        $start = $this->attempts->start(
            $storeSourceId,
            $sourceOrderId,
            $type,
            $initiatedById,
            $now,
            $expiresAt,
        );

        $attempt = $start->attempt;

        if ($attempt->initiatedByType === $type && $attempt->initiatedById === $initiatedById) {
            return $start->created
                ? StartAttemptOutcome::started($attempt)
                : StartAttemptOutcome::resumed($attempt);
        }

        return StartAttemptOutcome::refused(
            sprintf(
                'Another %s is already testing this order. Their attempt holds it until %s. '
                . 'Only one test at a time can be attributed to a person, so this one was not started.',
                strtolower($attempt->initiatedByType->label()),
                $attempt->expiresAt->format('H:i'),
            ),
            $attempt,
        );
    }
}
