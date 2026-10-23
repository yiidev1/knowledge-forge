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

    protected function templatePath(): string
    {
        return __DIR__ . '/practice-orders';
    }

    protected function defaultAudioFilter(): StoreAudioFilter
    {
        return StoreAudioFilter::WithAudio;
    }
}
