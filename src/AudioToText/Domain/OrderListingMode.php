<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * Which of the two things a store has is the one a listing has a row for.
 *
 * ## Why this is a choice at all
 *
 * A store page has always been built from **recordings**: a row existed because somebody uploaded or
 * downloaded audio and typed an order number against it. That was the only source there was.
 *
 * Original orders now arrive from the provider in their own right, whether or not anything was ever
 * recorded against them — so there are two populations, they overlap, and neither contains the other:
 *
 * ```
 * an order with a recording      in both
 * an order with no recording     orders only      — the new case, and the reason this exists
 * a recording whose order id names nothing        recordings only — a typo, or audio from elsewhere
 * an upload that named no order at all            recordings only
 * ```
 *
 * A listing therefore has to say which population it is a list *of*, and the answer is a business
 * decision rather than a technical one: a practice catalogue is a list of orders, and an audio
 * management screen is a list of recordings. So it is configuration, not a hard-coded rule.
 *
 * ## The two modes, exactly
 *
 * - {@see CaseOrderPrimary} — one row per imported order for the store. A matching recording is shown
 *   where there is one and the cell says so where there is not. A recording whose order is not in the
 *   mirror is **not** listed: there is no order for it to be a row about.
 * - {@see RecordingPrimary} — one row per recording group, which is what every one of these pages
 *   listed before this existed. A recording with no matching order is listed, with the order columns
 *   empty. An order with no recording is **not** listed.
 *
 * Each mode is one query over one primary table, not a union of two. That is deliberate: a union
 * would have to invent a sort key meaning the same thing for a conversation and for an order, and
 * would make "how many rows are there" a question with two answers to reconcile — which is how a
 * pager starts offering pages that come back empty.
 *
 * ## What this does NOT control
 *
 * Anything that is stored. Case orders are imported and recordings are imported regardless of the
 * mode, independently of each other, and neither import reads this. It decides which rows a listing
 * draws, and nothing else — so flipping it changes a screen and loses no data.
 *
 * It also does not reach the agent surface. That one requires an order **and** its audio, always; see
 * {@see OrderGroupFilter::$requireLinkedOrder}.
 */
enum OrderListingMode: string
{
    /** Orders are the rows. The default, and what the Case Order integration is for. */
    case CaseOrderPrimary = 'case-order';

    /** Recordings are the rows. What these pages did before, and still do where audio is the subject. */
    case RecordingPrimary = 'recording';

    /**
     * The mode one boolean setting asks for.
     *
     * A boolean rather than the two names, because that is what the operator was given:
     * `ORDER58_CASE_ORDER_PRIMARY=true`. Reading it in one place means the rest of the application
     * never asks "true meaning what".
     */
    public static function fromCaseOrderPrimary(bool $caseOrderPrimary): self
    {
        return $caseOrderPrimary ? self::CaseOrderPrimary : self::RecordingPrimary;
    }

    public function listsOrdersWithoutRecordings(): bool
    {
        return $this === self::CaseOrderPrimary;
    }

    public function listsRecordingsWithoutOrders(): bool
    {
        return $this === self::RecordingPrimary;
    }
}
