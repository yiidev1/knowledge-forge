<?php

declare(strict_types=1);

namespace App\Order58\Web\StoreAudio;

use App\Order58\Domain\AudioProviderDefaultInterface;
use App\Order58\Domain\StoreAudioCountsInterface;
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
final readonly class Action
{
    private const PER_PAGE = 36;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private StoreDirectoryReaderInterface $reader,
        private StoreAudioCountsInterface $audioCounts,
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
        $restrictTo = $audio === StoreAudioFilter::WithAudio
            ? $this->audioCounts->storesWithAudio()
            : null;

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

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'result' => $result,
                'search' => $search,
                'sourceStatus' => $sourceStatus,
                'audio' => $audio,
                'letter' => $letter,
                'page' => min($page, $result->pageCount()),
                // One query for the whole page, not one per card.
                'audioCounts' => $this->audioCounts->countsFor(array_map(
                    static fn(StoreDirectoryItem $item): int => $item->sourceId,
                    $result->items,
                )),
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
}
