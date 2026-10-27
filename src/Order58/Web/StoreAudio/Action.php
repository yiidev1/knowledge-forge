<?php

declare(strict_types=1);

namespace App\Order58\Web\StoreAudio;

use App\Order58\Domain\AudioProviderDefaultInterface;
use App\Order58\Domain\StoreAudioBreakdown;
use App\Order58\Domain\StoreAudioCountsInterface;
use App\Order58\Domain\StoreCaseRecordingCounts;
use App\Order58\Domain\StoreTopicAssignmentCountsInterface;
use App\Order58\Domain\StoreAudioFilter;
use App\Order58\Domain\StoreDirectoryFilter;
use App\Order58\Domain\StoreDirectoryItem;
use App\Order58\Domain\StoreDirectoryQuery;
use App\Order58\Domain\StoreDirectoryReaderInterface;
use App\Order58\Domain\StoreSourceStatusFilter;
use App\Shared\Web\Support\AlphabetIndex;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function array_map;
use function is_string;
use function max;
use function min;
use function trim;

/**
 * Pick a store to manage audio for (GET /admin/order58/store-audio).
 *
 * The same searchable, letter-bucketed store list as Store chat, reusing the same
 * {@see StoreDirectoryReaderInterface} — the search SQL, the letter counts and the pagination are not
 * reimplemented here, only asked for.
 *
 * **This page lives in Order58 on purpose.** Audio-to-Text may not name the Order58 module and no
 * module may name Audio-to-Text; `ModuleIsolationTest` matches both namespaces literally and fails the
 * build. Store chat solved the same problem years of commits ago by building its card link from a
 * *route name* rather than a class, and this page does exactly the same. Neither module names the
 * other, and nothing about the isolation rules had to be relaxed.
 *
 * Chat eligibility is deliberately not a filter here: a store with no knowledge base can still have a
 * recording transcribed, so it is not an axis at all.
 *
 * Source-active is a different matter. A store Order58 reports as inactive is not somewhere new
 * recordings should be sent, so its card is shown disabled — and the store page refuses the upload
 * server-side, because a disabled button is a hint, not a rule.
 */
/**
 * Not `final`: {@see OrderTestingAction} extends it so the Order Testing surface gets the same picker —
 * the same search, the same filters, the same cards and the same counts — under its own template, whose
 * cards link on to its own store page. The data this assembles is identical either way, which is the
 * reason the second surface inherits it rather than describing a store directory of its own.
 */
readonly class Action
{
    private const PER_PAGE = 36;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private StoreDirectoryReaderInterface $reader,
        private StoreAudioCountsInterface $audioCounts,
        /**
         * The store cards' second number: how many of this store's orders carry a topic.
         *
         * Batched like the audio counts beside it: one query for the page, never one per card.
         */
        private StoreTopicAssignmentCountsInterface $topicCounts,
        private AudioProviderDefaultInterface $providerDefault,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $search = is_string($params['q'] ?? null) ? trim((string) $params['q']) : '';
        $sourceStatus = StoreSourceStatusFilter::fromRequest(
            is_string($params['status'] ?? null) ? (string) $params['status'] : null,
        );
        $audio = StoreAudioFilter::fromRequest(
            is_string($params['audio'] ?? null) ? (string) $params['audio'] : null,
            $this->defaultAudioFilter(),
        );
        $letter = AlphabetIndex::normalize(is_string($params['letter'] ?? null) ? (string) $params['letter'] : null);
        $page = is_string($params['page'] ?? null) ? max(1, (int) $params['page']) : 1;

        // The transcription-settings dialog is opened by a real link carrying `?settings=1`, so it
        // works with JavaScript switched off — the page simply renders the dialog already open.
        // `admin.js` intercepts the same link and calls showModal() instead. This mirrors the chat
        // report drill-downs, where every trigger is likewise a working href first.
        $settingsOpen = ($params['settings'] ?? null) === '1';

        // Resolved once, before the directory query, and handed to it as a plain id restriction. The
        // reader then narrows its rows, its total and its letter counts together — a filter applied
        // afterwards would leave the pager promising pages that render empty.
        $restrictTo = match ($audio) {
            StoreAudioFilter::WithAudio => $this->audioCounts->storesWithAudio(),
            // A different table and no audio consulted: a store with orders and nothing recorded
            // belongs here, which is the entire point of offering the filter separately.
            StoreAudioFilter::CaseOrders => $this->audioCounts->storesWithCaseOrders(),
            // The Case Order recording metadata, and nothing uploaded: the same rule the cards below
            // count by, so a listed store never shows zero and a counted store is never missing.
            StoreAudioFilter::CaseRecordings => $this->audioCounts->storesWithCaseRecordings(),
            StoreAudioFilter::All => null,
        };

        $build = static fn(int $p): StoreDirectoryQuery => new StoreDirectoryQuery(
            $search,
            StoreDirectoryFilter::All,
            $letter,
            $p,
            self::PER_PAGE,
            sourceStatus: $sourceStatus,
            sourceIds: $restrictTo,
        );

        $result = $this->reader->search($build($page));

        // A stale ?page= from a bookmark, or a filter that shrank the result set, lands past the end.
        // Clamping shows the last page rather than an empty one.
        if ($page > $result->pageCount()) {
            $result = $this->reader->search($build($result->pageCount()));
        }

        // One grouped query for the page, and only in the mode that shows it.
        $caseRecordingCounts = $audio === StoreAudioFilter::CaseRecordings
            ? $this->audioCounts->caseRecordingCountsFor($this->shownIds($result->items))
            : [];

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render($this->templatePath(), [
                'result' => $result,
                'search' => $search,
                'sourceStatus' => $sourceStatus,
                'audio' => $audio,
                // Handed to the template so the URL builder omits exactly the value the parser above
                // puts back. Two surfaces land differently and a second constant in the view would be a
                // second rule; see defaultAudioFilter().
                'audioDefault' => $this->defaultAudioFilter(),
                'letter' => $letter,
                'page' => min($page, $result->pageCount()),
                // One query for the whole page, not one per card.
                'showsPractisable' => $this->showsPractisableCount(),
                // One query for the whole page, not one per card, and it carries both figures.
                /*
                 * Counted for the filter in force, not for the configured default.
                 *
                 * Each named filter says which population it is about, so its cards must count that
                 * one: "Uploaded audio" showing an order count — which is what
                 * `ORDER58_CASE_ORDER_PRIMARY` produced on every card — is a right number under the
                 * wrong word. Only `All stores` still defers to the surface's own seam, because only
                 * there has nobody said which population they meant.
                 */
                'audioCounts' => $audio === StoreAudioFilter::CaseRecordings
                    ? $this->caseRecordingBreakdowns($caseRecordingCounts)
                    : $this->audioCounts->countsFor(
                        $this->shownIds($result->items),
                        $audio->countsImportedOrders() ?? $this->cardsCountImportedOrders(),
                    ),
                // Calls and files beside the order headline, in the Case Order recordings mode only.
                'caseRecordingCounts' => $caseRecordingCounts,
                // The word above that number, decided with it so the two cannot disagree.
                'audioCountLabel' => $audio->cardMetricLabel($this->cardsCountImportedOrders()),
                // Which chips to draw. See audioFilterOptions() for why this is not just cases().
                'audioOptions' => $this->audioFilterOptions(),
                // The second card metric, asked the same way and for the same reason.
                'topicCounts' => $this->topicCounts->assignedOrdersFor($this->shownIds($result->items)),
                // The transcription default, shown here because this is where an administrator already
                // is when they think about store audio. Read through this module's own port and posted
                // to the owning module by route name — see AudioProviderDefaultInterface.
                //
                // It lives in a dialog rather than on the page: it is changed rarely, it applies to
                // every store rather than to the picker below it, and a permanent card for it pushed
                // the store list an administrator actually came for below the fold.
                'providerDefault' => $this->providerDefault->current(),
                'providerChoices' => $this->providerDefault->choices(),
                'settingsOpen' => $settingsOpen,
            ]);
    }

    /**
     * The Case Order recording counts as the card's headline: orders with a recording, twice.
     *
     * The headline and "available to practise" are the same number here — every such order is a stored
     * order of the store, which is all practising needs — so the second line correctly stays hidden.
     *
     * @param array<int, StoreCaseRecordingCounts> $counts
     *
     * @return array<int, StoreAudioBreakdown>
     */
    private function caseRecordingBreakdowns(array $counts): array
    {
        $breakdowns = [];

        foreach ($counts as $sourceId => $count) {
            $breakdowns[$sourceId] = new StoreAudioBreakdown($count->orders, $count->orders, $count->files, 0, 0, 0);
        }

        return $breakdowns;
    }

    /**
     * The store ids on this page, which is what both count readers are asked about.
     *
     * @param list<StoreDirectoryItem> $items
     *
     * @return list<int>
     */
    private function shownIds(array $items): array
    {
        return array_map(static fn(StoreDirectoryItem $item): int => $item->sourceId, $items);
    }

    /** The template this surface renders. Everything above it is the same question on both. */
    /**
     * Whether this picker adds "N available to practise" under the audio count.
     *
     * False here: on Manage Orders every grouped order with audio is listed, so the two numbers
     * would always be the same and the second would say nothing.
     *
     * The Practice Orders picker turns it on. Its own list applies `linkedOrdersOnly()` and leaves
     * out orders that resolve to no source order, so without the second line a store would show 4
     * on the card and 3 on the page with nothing explaining the gap.
     */
    protected function showsPractisableCount(): bool
    {
        return false;
    }

    protected function templatePath(): string
    {
        return __DIR__ . '/template';
    }

    /**
     * Where a request that names no `audio=` lands.
     *
     * **Neutral here, and deliberately so.** Opening this page on Uploaded audio was tried once and
     * reverted: every filter link omits a parameter that already equals its default, so "All stores"
     * generated a bare URL that the page read straight back as Uploaded audio and the click appeared
     * to do nothing. The menu entry carries `?audio=with` instead, which establishes the landing state
     * once and leaves every click after it the user's.
     *
     * A surface that overrides this must therefore hand the same value to its template, which the
     * caller above does — the omit rule and the parse rule are then the same fact, and the click that
     * broke last time cannot break again. {@see OrderTestingAction}, which lands on Uploaded audio.
     */
    /**
     * Which population this picker's cards count.
     *
     * `false` here, and for the same reason the audio store page itself answers recordings — see its
     * own `listingMode()` seam, which this module may not name: this picker leads to that page, whose
     * rows are recordings. A card is the headline for the page behind it, so counting imported orders
     * here would put a number on the card that the page does not list.
     *
     * {@see OrderTestingAction} overrides it to null — "whatever is configured" — because the practice
     * catalogue is the surface `ORDER58_CASE_ORDER_PRIMARY` governs. `StoreAudioOrderCountTest`
     * asserts that each card equals the page it links to, under both settings.
     */
    protected function cardsCountImportedOrders(): ?bool
    {
        return false;
    }

    protected function defaultAudioFilter(): StoreAudioFilter
    {
        return StoreAudioFilter::All;
    }

    /**
     * Which data-source chips this surface offers.
     *
     * The historical two by default, so adding a third case to the enum does not put a chip on every
     * picker that happens to share this partial. Practice Orders in particular requires a store to
     * have an order **and** audio, so a "Case Orders" chip there would list stores whose practice
     * page then shows nothing — a filter that promises rows it cannot deliver.
     *
     * A surface that offers a filter it does not list is still reachable by URL; this decides what is
     * advertised, not what is accepted, which keeps old links working everywhere.
     *
     * @return list<StoreAudioFilter>
     */
    protected function audioFilterOptions(): array
    {
        return [StoreAudioFilter::All, StoreAudioFilter::WithAudio];
    }
}
