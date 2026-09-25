<?php

declare(strict_types=1);

namespace App\Order58\Web\Calls;

use App\Integration\Order58Recording\CallSummary;
use App\Integration\Order58Recording\ChannelApiProbe;
use App\Integration\Order58Recording\FixtureAvailability;
use App\Integration\Order58Recording\FixtureCallSource;
use App\Integration\Order58Recording\LatestCallsRequest;
use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Application\TodayCallFilter;
use App\Order58\Domain\AudioProviderDefaultInterface;
use App\Order58\Domain\CallImportOutcome;
use App\Order58\Domain\CallImportRepositoryInterface;
use App\Order58\Domain\Order58ImportStatus;
use App\Order58\Domain\StoreDirectoryQuery;
use App\Order58\Domain\StoreDirectoryReaderInterface;
use App\Order58\Domain\StoreSourceStatusFilter;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Domain\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use DateTimeImmutable;
use Throwable;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function array_map;
use function is_string;
use function preg_match;

/**
 * Manage Order58 Calls (GET /admin/order58/calls).
 *
 * ## Nothing is fetched until it is asked for
 *
 * Opening this page contacts no provider. The call list appears only when a store is chosen **and**
 * `load=1` is present, which is what the Load button submits — the same rule the recording diagnostic
 * page follows, and for the same reason: a page that probes a third party on every render turns an
 * idle browser tab into traffic somebody else is rate-limiting.
 *
 * ## The history is local, always
 *
 * The lower half of the page reads the import tables and nothing else, so it renders at full speed with
 * the provider unreachable — which is the normal state of any machine outside the client's IP allowlist.
 * A failure to load today's calls therefore costs the call list, not the page.
 */
final readonly class Action
{
    /** Enough recent calls to cover a busy day, well inside the provider's own ceiling of 500. */
    private const LIMIT = 200;

    /** One page of history: long enough to find yesterday's problem, short enough to read. */
    private const HISTORY = 40;

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private StoreDirectoryReaderInterface $stores,
        private ChannelApiProbe $probe,
        private FixtureCallSource $fixtures,
        private TodayCallFilter $today,
        private CallImportRepositoryInterface $imports,
        private AudioProviderDefaultInterface $providerDefault,
        private AppTimeZone $appTimeZone,
        private ClockInterface $clock,
        private bool $importEnabled,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        $storeId = $this->storeId($params['store'] ?? null);
        $wantsCalls = ($params['load'] ?? null) === '1' && $storeId !== null;

        $now = $this->clock->now();
        $calls = [];
        $statuses = [];
        $problem = null;

        $usingFixtures = FixtureAvailability::isRequested($params['source'] ?? null);

        if ($wantsCalls) {
            [$calls, $problem] = $usingFixtures
                // Dev and test only, and only when asked for on this request. See FixtureAvailability.
                ? [$this->today->today($this->fixtures->today($now), $now), null]
                : $this->loadCalls($storeId, $now);

            if ($calls !== []) {
                $statuses = $this->imports->statusesFor(
                    $storeId,
                    array_map(static fn(CallSummary $c): string => $c->callSessionId, $calls),
                );
            }
        }

        return $this->viewRenderer
            // The list is a snapshot of a third party's state and the statuses move underneath it, so a
            // cached copy would show work that has already finished as still pending.
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'stores' => $this->storeOptions(),
                'selectedStore' => $storeId,
                'loaded' => $wantsCalls,
                'calls' => $calls,
                'statuses' => $statuses,
                'problem' => $problem,
                'businessDate' => $this->today->businessDate($now),
                'provider' => $this->providerDefault->current(),
                'providerChoices' => $this->providerDefault->choices(),
                'importEnabled' => $this->importEnabled,
                'usingFixtures' => $usingFixtures,
                'history' => $this->imports->history($storeId, self::HISTORY),
                'channels' => RecordingChannel::all(),
                'appTimeZone' => $this->appTimeZone,
                // Carried through so the page's own links keep whatever source the operator asked for.
                'source' => is_string($params['source'] ?? null) ? (string) $params['source'] : '',
                'outcomes' => CallImportOutcome::cases(),
                'importStatuses' => Order58ImportStatus::cases(),
            ]);
    }

    /**
     * Today's calls for one store, or the sentence explaining why there are none.
     *
     * A transport failure is caught rather than allowed to 500: this endpoint is unreachable from any
     * machine the client has not allowlisted, and a stack trace is a worse answer than "the recording
     * service could not be reached from this server".
     *
     * @return array{list<CallSummary>, ?string}
     */
    private function loadCalls(int $storeId, DateTimeImmutable $now): array
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

        $calls = $this->today->today($result->calls, $now);

        return [
            $calls,
            $calls === [] && $result->calls !== []
                // Worth distinguishing: an account with no calls at all and an account whose calls are
                // all from earlier days are different situations, and only one of them is a surprise.
                ? 'This store has recent calls, but none from today.'
                : null,
        ];
    }

    /**
     * Every store this application can import for, newest question first: is it active?
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
