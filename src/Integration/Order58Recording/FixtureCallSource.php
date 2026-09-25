<?php

declare(strict_types=1);

namespace App\Integration\Order58Recording;

use DateTimeImmutable;

use function sprintf;

/**
 * A deterministic list of "today's calls", for local work and tests only.
 *
 * ## Why this exists
 *
 * The latest-calls endpoint is gated by an IP allowlist, so from any machine the client has not
 * allowlisted every request fails before a single line of this page's own logic runs — the date filter,
 * the already-synced column, the selection, the queueing. Fixtures exercise all of it honestly labelled,
 * instead of leaving it untested until the day the IP is added.
 *
 * ## It cannot activate in production, and it cannot activate silently
 *
 * Both conditions of {@see FixtureAvailability} apply unchanged: `APP_ENV` must be one of the two
 * environments that may use fixtures — checked as an allow-list, so an unrecognised value falls through
 * to the live API — **and** the operator must ask for it with `?source=fixture` on that specific
 * request. There is no default and no sticky setting.
 *
 * ## The calls are dated now, on purpose
 *
 * They are generated against the date they are asked for, so they are always "today" by whatever rule
 * {@see \App\Order58\Application\TodayCallFilter} applies. A fixed date would drop out of the list at
 * midnight and take the page's behaviour with it.
 */
final readonly class FixtureCallSource
{
    /** Distinctive enough to recognise in a database, and plausible as provider ids. */
    private const SESSION_IDS = ['22487129', '22487119', '22487109'];

    private const ORDER_IDS = ['16547451', '16547441', ''];

    /**
     * Three calls for the given day, newest first, as the provider would order them.
     *
     * One deliberately carries no order id: the provider's `orderId` can be blank, and a page that has
     * never seen a blank one is a page that renders "—" for the first time in production.
     *
     * @return list<CallSummary>
     */
    public function today(DateTimeImmutable $now): array
    {
        $date = $now->format('Y-m-d');
        $calls = [];

        foreach (self::SESSION_IDS as $index => $sessionId) {
            $calls[] = new CallSummary(
                $sessionId,
                sprintf('%s %02d:%02d:16', $date, 6 - $index, 9 - $index),
                self::ORDER_IDS[$index] ?? '',
            );
        }

        return $calls;
    }
}
