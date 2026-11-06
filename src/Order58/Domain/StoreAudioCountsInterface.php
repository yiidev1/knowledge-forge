<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * How many conversions each store has.
 *
 * This module may not name the Audio-to-Text module — `ModuleIsolationTest` matches that namespace
 * literally and fails the build — so the store-audio picker asks its own port rather than reaching for
 * that module's repository. The implementation reads the audio tables directly, which is the same
 * shape, in the other direction, as Audio-to-Text reading the store mirror in `DbAudioStoreLookup`:
 * a table name is not a namespace, and one small query is cheaper than a dependency between modules.
 *
 * **Conversions, not jobs.** A separate Customer + Agent upload is one conversion and two jobs, and
 * this is the number an administrator counts, so it must never be the second one.
 */
interface StoreAudioCountsInterface
{
    /**
     * Conversion counts for the stores on one page, keyed by store source id.
     *
     * One query for the whole page rather than one per card: a page of 36 stores would otherwise be 36
     * round trips to print 36 numbers. Stores with nothing uploaded are absent from the result rather
     * than present as zero — the caller defaults, and an absent key and a zero mean the same thing.
     *
     * Returns a {@see StoreAudioBreakdown} rather than a bare int so the card can show how the total
     * splits by recording type. The breakdown is gathered by the SAME query — conditional aggregates
     * alongside the existing `COUNT(*)` — so the round-trip count did not change when it was added.
     * `$total` still means exactly what it meant before: rows in `audio_conversations` for the store.
     *
     * @param list<int> $sourceIds
     *
     * Every breakdown carries both figures: how many grouped orders hold audio, and how many of
     * those can be practised. Both pickers show the same headline — the store holds what it holds —
     * and the Practice Orders card names the smaller number beside it, because that is the one its
     * own list will show.
     *
     * @return array<int, StoreAudioBreakdown>
     */
    public function countsFor(
        array $sourceIds,
        /**
         * Whether the headline counts imported orders rather than recordings, or null for the
         * configured default.
         *
         * The card is the headline for the page it links to, so the two have to count the same
         * population: a card reading "4" beside a page listing 336 is worse than either number alone.
         * `ORDER58_CASE_ORDER_PRIMARY` decides it for the practice pickers and is injected once;
         * `false` is passed by the audio picker, whose page lists recordings because managing them is
         * what it is for.
         *
         * A boolean rather than the audio module's own listing-mode enum, because
         * `ModuleIsolationTest` forbids this module from naming that one — the same reason the
         * grouping expression in the implementation is a copy rather than a call.
         */
        ?bool $caseOrderPrimary = null,
    ): array;

    /**
     * Every store that has at least one conversion.
     *
     * Used to restrict the directory query itself, so the "Uploaded audio" filter narrows the rows,
     * the total and the letter counts together — a filter that only hid cards would leave a pager
     * promising pages that render empty.
     *
     * @return list<int>
     */
    public function storesWithAudio(): array;

    /**
     * Every store that has at least one imported Case Order.
     *
     * The same job as {@see storesWithAudio()} for the other data source, and deliberately a separate
     * question rather than a parameter on that one: it reads a different table and consults no audio
     * at all. A store with four hundred orders and nothing ever recorded belongs in this list, which
     * is the whole reason the filter exists.
     *
     * @return list<int>
     */
    public function storesWithCaseOrders(): array;

    /**
     * Case Order call recording counts for the stores on one page, keyed by store source id.
     *
     * One grouped query for the page, from the recording metadata alone — `audio_conversations` is not
     * consulted. Stores with no current recording link are absent rather than present as zero.
     *
     * @param list<int> $sourceIds
     *
     * @return array<int, StoreCaseRecordingCounts>
     */
    public function caseRecordingCountsFor(array $sourceIds): array;

    /**
     * Every store with at least one Case Order that has a current call recording link.
     *
     * The same rule as {@see caseRecordingCountsFor()}, so a store listed under the filter always shows
     * a non-zero card and a store with a non-zero card is always listed.
     *
     * @return list<int>
     */
    public function storesWithCaseRecordings(): array;
}
