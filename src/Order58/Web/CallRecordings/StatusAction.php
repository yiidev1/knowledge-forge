<?php

declare(strict_types=1);

namespace App\Order58\Web\CallRecordings;

use App\Order58\Application\RecordingCompanyResolver;
use App\Order58\Domain\CallImportRepositoryInterface;
use App\Order58\Domain\Exception\RecordingCompanyMissing;
use App\Shared\Audio\RecordingAcquisition;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function array_slice;
use function array_values;
use function explode;
use function is_string;
use function json_encode;
use function preg_match;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * What the visible calls' recordings are doing (GET /admin/order58/call-recordings/status).
 *
 * ## One request for the whole page
 *
 * The page sends every call session id it is showing and gets them all back in one answer. A per-row
 * endpoint would have turned a twenty-call day into twenty requests every five seconds from every open
 * tab, which is how a live page becomes a load problem. Behind it is a single statement matching the
 * leading two columns of `ux_order58_call_imports_identity`, so the cost is one index range read
 * whatever the page is showing.
 *
 * ## It answers only about rows this store actually has
 *
 * The store is validated through {@see RecordingCompanyResolver} — the same server-side resolution the
 * download action uses — so a request naming a store the administrator cannot reach is refused before
 * any query runs. The call ids are then matched **against that store's own rows**, so an id belonging to
 * someone else simply has no row to return and is absent from the answer. Nothing here can be used to
 * learn whether another store has a given call.
 *
 * ## What it does not return
 *
 * No error messages, no file sizes, no paths, no conversation ids, no batch, no admin. Five enum values
 * per channel and the counts derived from them — enough to redraw the cell and nothing that was written
 * for a log.
 */
final readonly class StatusAction
{
    /**
     * As many calls as the page can show. The list endpoint is capped at 200 and the browser sends what
     * it rendered, so this only ever truncates a hand-made request.
     */
    private const MAX_CALLS = 200;

    public function __construct(
        private CallImportRepositoryInterface $imports,
        private RecordingCompanyResolver $company,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $storeId = $this->storeId($params['store'] ?? null);

        if ($storeId === null) {
            return $this->json(['calls' => [], 'active' => false], 400);
        }

        try {
            // Not for the code itself, which is unused here — for the refusal. A store this
            // administrator cannot download for is one they cannot poll either, and resolving it here
            // means that rule lives in one place rather than being restated per endpoint.
            $this->company->forStore($storeId);
        } catch (RecordingCompanyMissing) {
            return $this->json(['calls' => [], 'active' => false], 404);
        }

        $wanted = $this->callIds($params['calls'] ?? null);

        if ($wanted === []) {
            return $this->json(['calls' => [], 'active' => false]);
        }

        $acquisitions = $this->imports->acquisitionsFor($storeId, $wanted);

        $calls = [];
        $active = false;

        foreach ($acquisitions as $sessionId => $acquisition) {
            $calls[$sessionId] = $this->describe($acquisition);
            $active = $active || $acquisition->isActive();
        }

        // `active` is the page's permission to keep asking. Computed here rather than inferred in the
        // browser, so "stop polling when nothing is happening" is one rule on one side.
        return $this->json(['calls' => $calls, 'active' => $active]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(RecordingAcquisition $acquisition): array
    {
        $channels = [];

        foreach ($acquisition->channels as $channel => $state) {
            $channels[$channel] = [
                'state' => $state->value,
                'label' => $state->label(),
                'badge' => $state->badge(),
                // The step mark, so the browser does not have to map five states to four marks and
                // get it wrong the day a sixth is added.
                'step' => $state->step(),
            ];
        }

        $outcome = $acquisition->outcome();

        return [
            'outcome' => $outcome->value,
            'outcomeLabel' => $outcome->label(),
            'outcomeBadge' => $outcome->badge(),
            // The bar: channels the provider has answered for. Never bytes — nothing upstream reports a
            // total to measure them against, so a figure like 42% could not be produced honestly.
            'percentChecked' => $acquisition->percentChecked(),
            'progressText' => $acquisition->progressText(),
            // The authoritative sentence, which is what actually arrived.
            'availabilityText' => $acquisition->availabilityText(),
            // What is happening now, for the line under the bar. Null once nothing is, which is also
            // when the panel stops being drawn.
            'currentStep' => $acquisition->currentStep(),
            'active' => $acquisition->isActive(),
            'channels' => $channels,
        ];
    }

    /**
     * The comma-separated ids the page is showing, shape-checked and bounded.
     *
     * Whether any of them is this store's call is settled by the query, which is the check that matters;
     * this keeps obvious rubbish out of an `IN` clause and caps how much can be asked for at once.
     *
     * @return list<string>
     */
    private function callIds(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $raw) as $candidate) {
            if (preg_match('/\A\d{1,20}\z/', $candidate) === 1) {
                $ids[$candidate] = $candidate;
            }
        }

        return array_slice(array_values($ids), 0, self::MAX_CALLS);
    }

    private function storeId(mixed $raw): ?int
    {
        if (!is_string($raw) || preg_match('/\A\d{1,9}\z/', $raw) !== 1) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Content-Length', (string) strlen($body))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store, private')
            ->withBody($this->streamFactory->createStream($body));
    }
}
