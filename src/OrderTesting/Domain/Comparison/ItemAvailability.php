<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain\Comparison;

/**
 * Whether the original order's line items can be compared, and if not, why not.
 *
 * ## The distinction this whole type exists for
 *
 * "The original has no items" and "we do not know what the original's items were" are different facts,
 * and conflating them is the defect this phase fixes. An original whose items have never been fetched
 * looks exactly like an original with an empty basket if you only look at the item list — so a
 * comparison that trusted an empty list reported every line the trainee entered as `ExtraInDemo`,
 * telling them they had invented an order they had in fact recreated correctly.
 *
 * Only the provider answering `OrderItems: []` means "no items". Everything else means "not yet known",
 * and the page says so instead of blaming anybody.
 *
 * ## A kept snapshot stays usable
 *
 * A failed refresh and an in-flight refresh both leave the previously fetched items in place, and both
 * are worth comparing against — with the page saying plainly that the data is from an earlier fetch.
 * Discarding a good snapshot because a later request failed would throw away the only item data there
 * is, in order to display nothing.
 */
enum ItemAvailability: string
{
    /** No sync row, or `PENDING`: nobody has ever asked the provider. */
    case NeverRequested = 'NEVER_REQUESTED';

    /** `QUEUED`: asked for, not yet fetched. */
    case Queued = 'QUEUED';

    /** `PROCESSING` with nothing previously stored. */
    case Fetching = 'FETCHING';

    /** `FAILED` with nothing previously stored — the one case where we simply do not know. */
    case Unavailable = 'UNAVAILABLE';

    /** `SYNCED`: current, complete, comparable. */
    case Synced = 'SYNCED';

    /** `EMPTY`: the provider stated this order has no lines. Authoritative, and comparable. */
    case AuthoritativeEmpty = 'AUTHORITATIVE_EMPTY';

    /** `FAILED` over a kept snapshot: compare it, and say it is stale. */
    case StaleAfterFailure = 'STALE_AFTER_FAILURE';

    /** `PROCESSING` over a kept snapshot: compare it, and say a refresh is running. */
    case StaleWhileFetching = 'STALE_WHILE_FETCHING';

    /**
     * Whether the item lists may be compared at all.
     *
     * The three "not yet known" cases return false, which is what makes every demo line
     * {@see ComparisonStatus::Uncomparable} rather than an accusation.
     */
    public function isComparable(): bool
    {
        return match ($this) {
            self::Synced, self::AuthoritativeEmpty, self::StaleAfterFailure, self::StaleWhileFetching => true,
            self::NeverRequested, self::Queued, self::Fetching, self::Unavailable => false,
        };
    }

    /** Whether the items being compared came from an earlier fetch than the latest attempt. */
    public function isStale(): bool
    {
        return $this === self::StaleAfterFailure || $this === self::StaleWhileFetching;
    }

    /**
     * What the page says above the item table.
     *
     * Written for an administrator who has to decide what to do next, so each one either names the
     * action available or says that none is needed.
     */
    public function note(): string
    {
        return match ($this) {
            self::NeverRequested
                => 'Original order items have not been synchronized yet, so the lines below cannot be '
                . 'compared. Use "Sync Original Order Items" to fetch them.',
            self::Queued
                => 'Original order items are queued for synchronization. The comparison will be available '
                . 'once they arrive.',
            self::Fetching
                => 'Original order items are being synchronized now. The comparison will be available once '
                . 'they arrive.',
            self::Unavailable
                => 'Original order items could not be synchronized and none have ever been retrieved, so '
                . 'the lines below cannot be compared. This is not a difference in the demo order.',
            self::StaleAfterFailure
                => 'Showing the last successfully synchronized items. The most recent refresh failed, so '
                . 'these may be out of date.',
            self::StaleWhileFetching
                => 'Showing the last successfully synchronized items while a refresh is running.',
            self::Synced, self::AuthoritativeEmpty => '',
        };
    }

    /** CSS modifier, so the template carries no decision about how to colour this. */
    public function modifier(): string
    {
        return match ($this) {
            self::Synced, self::AuthoritativeEmpty => 'ok',
            self::StaleAfterFailure, self::Unavailable => 'warning',
            self::NeverRequested, self::Queued, self::Fetching, self::StaleWhileFetching => 'info',
        };
    }

    /**
     * The availability for one stored sync state.
     *
     * `$hasSnapshot` is `items_synced_at IS NOT NULL` — the only thing that distinguishes "never
     * successfully synced" from "a previous snapshot is still there". An unrecognised status is treated
     * as never requested, which is the answer that blames nobody.
     */
    public static function of(?string $itemsStatus, bool $hasSnapshot): self
    {
        return match ($itemsStatus) {
            'SYNCED' => self::Synced,
            'EMPTY' => self::AuthoritativeEmpty,
            'QUEUED' => self::Queued,
            'PROCESSING' => $hasSnapshot ? self::StaleWhileFetching : self::Fetching,
            'FAILED' => $hasSnapshot ? self::StaleAfterFailure : self::Unavailable,
            default => self::NeverRequested,
        };
    }
}
