<?php

declare(strict_types=1);

namespace App\Order58\Web\StoreAudio;

use App\Order58\Domain\StoreAudioFilter;

/**
 * The store picker, for the Order Testing surface.
 *
 * ## Why this lives here and not in the Audio-to-Text module
 *
 * Picking a store is a **store directory** question — search, source-status filters, the alphabet index,
 * paging — and that directory is this module's, with its reader, its filters and its audio counts. The
 * audio module may not name `App\Order58`, so a picker written over there could not have used any of it
 * and would have had to describe a second store directory. This one inherits the whole of it.
 *
 * ## What differs: one line
 *
 * The template. Its cards link on to `/order-testing/store/{sourceId}` rather than to the audio page, and
 * from there the operator stays inside Order Testing. Everything that decides *which* stores are shown
 * and what each card says is the parent's, so the two pickers cannot drift apart on the thing that
 * matters — what a store is and how many recordings it holds.
 *
 * Both templates address their destinations by **route name**, never by class: a name is not a namespace,
 * so module isolation holds in both directions.
 */
final readonly class OrderTestingAction extends Action
{
    protected function templatePath(): string
    {
        return __DIR__ . '/order-testing';
    }

    /**
     * This surface lands on the stores that actually hold recordings.
     *
     * Order Testing exists to work through calls, so a store with nothing uploaded is not a starting
     * point here — it is noise, and on this instance it is most of the directory. The audio picker
     * stays neutral and has its menu entry carry `?audio=with`; this one answers the same way with no
     * query string at all, because it has no other landing state worth having.
     *
     * The value reaches the template as well as the parser, so "All stores" emits an explicit
     * `?audio=all` rather than the bare address — which is what made the same default unworkable on
     * the page above. {@see Action::defaultAudioFilter()}.
     */
    protected function defaultAudioFilter(): StoreAudioFilter
    {
        return StoreAudioFilter::WithAudio;
    }
}
