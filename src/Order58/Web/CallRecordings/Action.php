<?php

declare(strict_types=1);

namespace App\Order58\Web\CallRecordings;

use App\Integration\Order58Recording\CallSummary;
use App\Integration\Order58Recording\ChannelApiProbe;
use App\Integration\Order58Recording\FixtureAvailability;
use App\Integration\Order58Recording\FixtureCallSource;
use App\Integration\Order58Recording\LatestCallsRequest;
use App\Order58\Application\TodayCallFilter;
use App\Order58\Domain\CallImportRepositoryInterface;
use App\Order58\Domain\StoreDirectoryQuery;
use App\Order58\Domain\StoreDirectoryReaderInterface;
use App\Order58\Domain\StoreSourceStatusFilter;
use App\Shared\Domain\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use DateTimeImmutable;
use Throwable;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function array_map;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Order58 Call Recordings (GET /admin/order58/call-recordings).
 *
 * ## What this page is for, and what the calls page is for
 *
 * The calls page brings a recording in **so that it can be transcribed**. This one brings a recording in
 * so that it can be *heard*, and stops there. Both write to the same tables and are drained by the same
 * worker; the only difference is one column on the batch, {@see \App\Order58\Domain\CallImportMode}.
 *
 * That difference is the whole feature. A transcript costs CPU or money, and until now there was no way
 * to listen to a call before deciding whether its text was worth either. Downloading here commits to
 * neither: the audio lands playable on the store's Audio to Text page, each channel offering to be
 * transcribed later, one at a time, by somebody who has heard it.
 *
 * ## Nothing is fetched until it is asked for
 *
 * Opening this page contacts no provider. The call list appears only when a store is chosen **and**
 * `load=1` is present, which is what the Load button submits — the same rule the calls page follows, and
 * for the same reason: a page that probes a third party on every render turns an idle browser tab into
 * traffic somebody else is rate-limiting.
 *
 * ## No history here
 *
 * Deliberately. The calls page already has one, across every store and paged, and a second copy of it
 * would be a second thing to keep right. What this page shows about a call it already downloaded is one
 * word in the table, and the audio itself is on the store's own page where it is actually useful.
 */
final readonly class Action
{
    /** Enough recent calls to cover a busy day, well inside the provider's own ceiling of 500. */
    private const LIMIT = 200;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private StoreDirectoryReaderInterface $stores,
        private ChannelApiProbe $probe,
        private FixtureCallSource $fixtures,
        private TodayCallFilter $today,
        private CallImportRepositoryInterface $imports,
        private ClockInterface $clock,
        private UrlGeneratorInterface $urlGenerator,
        private bool $importEnabled,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        $storeId = $this->storeId($params['store'] ?? null);
        $wantsCalls = ($params['load'] ?? null) === '1' && $storeId !== null;

        $now = $this->clock->now();

        $date = $this->requestedDate($params['date'] ?? null, $now);
        $calls = [];
        $statuses = [];
        $problem = null;

        $usingFixtures = FixtureAvailability::isRequested($params['source'] ?? null);

        if ($wantsCalls) {
            [$calls, $problem] = $usingFixtures
                // Dev and test only, and only when asked for on this request. See FixtureAvailability.
                ? [$this->today->onDate($this->fixtures->today($now), $date), null]
                : $this->loadCalls($storeId, $date);

            if ($calls !== []) {
                // Per channel, not folded into one status per call: this page reports the three
                // recordings individually, and the poll endpoint answers from the same read.
                $statuses = $this->imports->acquisitionsFor(
                    $storeId,
                    array_map(static fn(CallSummary $c): string => $c->callSessionId, $calls),
                );
            }
        }

        return $this->viewRenderer
            // The list is a snapshot of a third party's state and the statuses move underneath it, so a
            // cached copy would show audio that has already arrived as still absent.
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'stores' => $this->storeOptions(),
                'selectedStore' => $storeId,
                'loaded' => $wantsCalls,
                'calls' => $calls,
                'statuses' => $statuses,
                'problem' => $problem,
                'businessDate' => $date,
                // Today, separately: the date field offers nothing later, because the provider cannot
                // have recorded a call that has not happened.
                'today' => $this->today->businessDate($now),
                'importEnabled' => $this->importEnabled,
                'usingFixtures' => $usingFixtures,
                // Carried through so the page's own links keep whatever source the operator asked for.
                'source' => is_string($params['source'] ?? null) ? (string) $params['source'] : '',
                // One endpoint for the whole table. The page appends the ids it is showing.
                'statusUrl' => $this->urlGenerator->generate('order58.call-recordings.status'),
                'historyUrl' => $this->urlGenerator->generate('order58.call-recordings.history'),
            ]);
    }

    /**
     * One day's calls for one store, or the sentence explaining why there are none.
     *
     * A transport failure is caught rather than allowed to 500: this endpoint is unreachable from any
     * machine the client has not allowlisted, and a stack trace is a worse answer than "the recording
     * service could not be reached from this server".
     *
     * @return array{list<CallSummary>, ?string}
     */
    private function loadCalls(int $storeId, string $date): array
    {
        $request = LatestCallsRequest::fromStrings((string) $storeId, (string) self::LIMIT);

        if ($request === null) {
            return [[], 'That store id cannot be used to look up calls.'];
        }

        try {
            $result = $this->probe->latestCalls($request);
        } catch (Throwable $e) {
            return [[], 'The recording service could not be reached from this server. ' . $e->getMessage()];
        }

        if (!$result->diagnosis->isSuccess()) {
            return [[], $result->diagnosis->headline . ' ' . $result->diagnosis->advice];
        }

        $calls = $this->today->onDate($result->calls, $date);

        return [
            $calls,
            $calls === [] && $result->calls !== []
                // Worth distinguishing: an account with no calls at all and an account whose recent
                // calls are all from other days are different situations, and only one is a surprise.
                //
                // The window matters and is stated. The provider is asked for the latest LIMIT calls and
                // this filters them here — it takes no date — so a day far enough back can fall outside
                // that window entirely.
                ? sprintf(
                    'This store has recent calls, but none from %s. Only its most recent %d calls are '
                    . 'searched, so an older date may fall outside that window.',
                    $date,
                    self::LIMIT,
                )
                : null,
        ];
    }

    /**
     * The `YYYY-MM-DD` the operator asked for, or today.
     *
     * Checked by reconstruction rather than by a pattern: `2026-02-30` matches any reasonable regular
     * expression and is not a day. Re-formatting what was parsed and comparing it back is what rejects
     * that, and it rejects `2026-9-1` too — a shape this page never produces and the provider's own
     * dates never take.
     *
     * Anything else is today. A malformed value here is a typed URL, not a state worth an error
     * message, and the field shows which day was actually used.
     */
    private function requestedDate(mixed $value, DateTimeImmutable $now): string
    {
        if (!is_string($value) || $value === '') {
            return $this->today->businessDate($now);
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value
            ? $value
            : $this->today->businessDate($now);
    }

    /**
     * Every store this application can download for.
     *
     * Read through the directory rather than the mirror, because the authoritative active flag is
     * `knowledge_bases.source_active` — `order58_stores.active` is 0 for every row in this database and
     * a dropdown built on it would be empty.
     *
     * @return array<int, string> source id => name
     */
    private function storeOptions(): array
    {
        $result = $this->stores->search(new StoreDirectoryQuery(
            perPage: 1000,
            sourceStatus: StoreSourceStatusFilter::Active,
        ));

        $options = [];

        foreach ($result->items as $store) {
            $options[$store->sourceId] = $store->name;
        }

        return $options;
    }

    private function storeId(mixed $raw): ?int
    {
        if (!is_string($raw) || preg_match('/\A\d{1,9}\z/', $raw) !== 1) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }
}
