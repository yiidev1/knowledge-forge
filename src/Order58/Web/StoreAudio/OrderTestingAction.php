<?php

declare(strict_types=1);

namespace App\Order58\Web\StoreAudio;

use App\Order58\Domain\AudioProviderDefaultInterface;
use App\Order58\Domain\StoreAudioCountsInterface;
use App\Order58\Domain\StoreAudioFilter;
use App\Order58\Domain\StoreDirectoryReaderInterface;
use App\Order58\Domain\StoreTopicAssignmentCountsInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

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
    /**
     * Everything the picker needs, plus the one setting this surface adds.
     *
     * Spelled out rather than inherited because a subclass that adds a dependency has to: the parent
     * promotes its five, and PHP gives a child no way to append to that list. They are passed
     * straight through, so the two cannot diverge on anything but the new value.
     */
    public function __construct(
        WebViewRenderer $viewRenderer,
        StoreDirectoryReaderInterface $reader,
        StoreAudioCountsInterface $audioCounts,
        StoreTopicAssignmentCountsInterface $topicCounts,
        AudioProviderDefaultInterface $providerDefault,
        /**
         * The configured landing data source, as written in `.env`.
         *
         * Injected as the raw string and interpreted in the domain, so this class carries
         * configuration rather than policy — a fourth data source later changes the enum, not this.
         */
        private string $defaultSource = 'case_orders',
    ) {
        parent::__construct($viewRenderer, $reader, $audioCounts, $topicCounts, $providerDefault);
    }

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
    /**
     * The practice pickers' cards follow `ORDER58_CASE_ORDER_PRIMARY`.
     *
     * Null means "whatever is configured", matching the page this card links to — whose own
     * `listingMode()` seam answers the same way, and which this module may not name. Practice Orders
     * inherits this, so the two practice pickers and their two pages all count one population.
     */
    protected function cardsCountImportedOrders(): ?bool
    {
        return null;
    }

    /**
     * The landing filter, read from configuration rather than decided here.
     *
     * `MANAGE_ORDERS_DEFAULT_SOURCE` is the one value that moves it, and nothing below this line
     * knows what it can say — the words are normalised once, in
     * {@see StoreAudioFilter::fromConfiguredDefault()}. Switching the page back to opening on
     * uploaded audio is therefore one line of `.env` and no code at all.
     *
     * This decides only the *landing* state. An explicit `?audio=` always wins, so a bookmark, a
     * chip the operator clicked, and the Back button all keep working exactly as before.
     */
    protected function defaultAudioFilter(): StoreAudioFilter
    {
        return StoreAudioFilter::fromConfiguredDefault($this->defaultSource);
    }

    /**
     * Manage Orders is the surface that works both populations, so it offers all three.
     *
     * Named rather than `cases()`, so a filter added for another surface — Practice Orders' Case
     * Order recordings — does not appear here as a chip nobody asked for.
     *
     * @return list<StoreAudioFilter>
     */
    protected function audioFilterOptions(): array
    {
        return [StoreAudioFilter::All, StoreAudioFilter::WithAudio, StoreAudioFilter::CaseOrders];
    }
}
