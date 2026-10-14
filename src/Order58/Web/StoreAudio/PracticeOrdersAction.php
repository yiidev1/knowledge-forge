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
    protected function templatePath(): string
    {
        return __DIR__ . '/practice-orders';
    }

    protected function defaultAudioFilter(): StoreAudioFilter
    {
        return StoreAudioFilter::WithAudio;
    }
}
