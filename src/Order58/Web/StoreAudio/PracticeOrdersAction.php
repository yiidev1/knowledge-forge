<?php

declare(strict_types=1);

namespace App\Order58\Web\StoreAudio;

use App\Order58\Domain\StoreAudioFilter;

/**
 * The store picker, for the Practice Orders surface.
 *
 * The third page built on this one, and the cheapest: two methods. Which stores appear, how they are
 * searched, filtered, lettered, paged and counted is entirely the parent's, so the three pickers cannot
 * disagree about what a store is or how many recordings it holds.
 *
 * What differs is where a card goes — `/practice-orders/store/{sourceId}` — and that lives in the
 * template, addressed by route name rather than by class, so module isolation holds in both directions.
 *
 * Lands on stores that hold recordings, for the same reason Order Testing does: practice means working
 * through a call, and a store with nothing uploaded is not somewhere to start.
 */
final readonly class PracticeOrdersAction extends Action
{
    /**
     * The card shows the store's whole audio count, and names how much of it can be practised.
     *
     * The headline deliberately matches Manage Orders: the store holds what it holds, and two
     * pickers disagreeing about that reads as a bug whichever number is right. What differs is the
     * second line — this page's own list leaves out orders that resolve to no source order, so the
     * card says how many remain rather than letting the reader discover the gap by counting rows.
     */
    protected function showsPractisableCount(): bool
    {
        return true;
    }

    /**
     * **Always recordings here, whatever `ORDER58_CASE_ORDER_PRIMARY` says.**
     *
     * This used to answer `null`, meaning "follow the configured default" — and with that setting on,
     * the headline became `COUNT(*)` of `order58_orders` under the words "Audio Recordings". A store
     * with eight orders and nothing uploaded therefore advertised eight recordings it did not have.
     *
     * Practising needs a recording to listen to: an order with no audio is nothing to practise
     * against, which is exactly what the smaller "available to practise" figure beside this one has
     * always said. So the headline is the audio, and it is stated here rather than configured,
     * because no setting should be able to turn this card into a count of something else.
     *
     * Deliberately NOT the global setting and NOT the shared reader's default: `DbStoreAudioCounts`
     * is used by the Manage Orders picker too, and its behaviour there is unchanged.
     */
    protected function cardsCountImportedOrders(): ?bool
    {
        return false;
    }

    protected function templatePath(): string
    {
        return __DIR__ . '/practice-orders';
    }

    /**
     * Lands on the Case Order recordings: the stores whose orders carry a call recording to practise
     * with, counted as orders with a recording.
     *
     * Set at the owner's request on 27 October 2026; it was Uploaded audio before. The filter links
     * omit whatever equals this, so the bare `/practice-orders` the menu links to is this view, and the
     * Uploaded audio chip now carries `?audio=with`.
     */
    protected function defaultAudioFilter(): StoreAudioFilter
    {
        return StoreAudioFilter::CaseRecordings;
    }

    /**
     * The two audio sources this page can practise with, each a chip of its own.
     *
     * "Case Order recordings" lists the stores whose Case Orders carry a call recording link, counted
     * as orders with a recording. It is a separate chip rather than a change to "Uploaded audio",
     * which keeps counting exactly what it always counted, so neither number can absorb the other and
     * nothing that already worked moves.
     *
     * @return list<StoreAudioFilter>
     */
    protected function audioFilterOptions(): array
    {
        return [StoreAudioFilter::All, StoreAudioFilter::WithAudio, StoreAudioFilter::CaseRecordings];
    }
}
