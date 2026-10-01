<?php

declare(strict_types=1);

namespace App\Order58\Web\CallRecordings;

use App\Auth\Application\CurrentAdmin;
use App\Integration\Order58Recording\CallSummary;
use App\Integration\Order58Recording\ChannelApiProbe;
use App\Integration\Order58Recording\FixtureAvailability;
use App\Integration\Order58Recording\FixtureCallSource;
use App\Integration\Order58Recording\LatestCallsRequest;
use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Application\RecordingCompanyResolver;
use App\Order58\Application\TodayCallFilter;
use App\Order58\Domain\AudioProviderDefaultInterface;
use App\Order58\Domain\CallImportMode;
use App\Order58\Domain\CallImportRepositoryInterface;
use App\Order58\Domain\Exception\RecordingCompanyMissing;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use DateTimeImmutable;
use Throwable;

use function array_key_exists;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Ask for the selected calls' recordings (POST /admin/order58/call-recordings/download).
 *
 * Every security property of the calls page's own sync is kept here unchanged, because they are not
 * conveniences — each one closes something a browser could otherwise do:
 *
 * ## The browser's list of calls is never believed
 *
 * A checkbox posts a call session id, and a request can post any string at all. So this asks the
 * provider for the calls of the day the page was showing **again** and intersects: an id that is not in
 * that answer is dropped without comment. That costs one extra request per press and removes a whole
 * class of problem — a forged id would otherwise become a path segment in a fetch, and a stale tab's ids
 * would ask for calls that no longer exist.
 *
 * The call's `callTime` comes from that fresh answer too, never from the form. It decides the date the
 * recording is fetched with, and a date the browser could choose is a date that can be wrong.
 *
 * ## `company` is resolved, not accepted
 *
 * {@see RecordingCompanyResolver} reads it from the selected store. A posted `company` is ignored
 * outright rather than validated — there is no value a browser could send that this should prefer to the
 * store's own, so there is nothing to validate.
 *
 * ## Nothing is downloaded here
 *
 * This writes rows and returns. The audio is fetched by `kf:order58:import-recordings`, one channel per
 * tick, because a web request that downloads sixty WAVs is a web request that times out halfway through
 * and leaves nobody able to say which thirty arrived.
 *
 * ## What is different from the calls page, and it is only this
 *
 * The batch is written with {@see CallImportMode::DownloadOnly}, and there is no provider choice and no
 * AI-audio option to make — both are decisions about *text*, and this page has not asked for any. A
 * provider value is still recorded on the batch because the column is not nullable, but nothing reads it
 * for a download-only row: when somebody eventually asks for a transcript, the provider is captured at
 * that moment, which may be months later and may be a different one.
 */
final readonly class DownloadAction
{
    private const LIMIT = 200;

    public function __construct(
        private ChannelApiProbe $probe,
        private FixtureCallSource $fixtures,
        private TodayCallFilter $today,
        private CallImportRepositoryInterface $imports,
        private RecordingCompanyResolver $company,
        private AudioProviderDefaultInterface $providerDefault,
        private CurrentAdmin $currentAdmin,
        private ClockInterface $clock,
        private Redirect $redirect,
        private FlashMessages $flash,
        private bool $importEnabled,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $form = FormData::fromRequest($request);
        $storeId = $this->positiveInt($form->string('store'));
        $source = $form->string('source');

        // The day the page was showing, carried in a hidden field beside `store`. Resolved before any
        // early return so every redirect below comes back to the day the operator was working on,
        // rather than silently snapping to today.
        $date = $this->requestedDate($form->string('date'), $this->clock->now());

        if ($storeId === null) {
            $this->flash->error('Choose a store first.');

            return $this->back(null, $source);
        }

        // A real gate, not a UI hint: a form submitted from a page rendered before it was turned off
        // must not ask for anything either.
        if (!$this->importEnabled) {
            $this->flash->error(
                'Recording download is turned off on this server (ORDER58_RECORDING_IMPORT_ENABLED). '
                    . 'Nothing was requested.',
            );

            return $this->back($storeId, $source, $date);
        }

        $selected = $this->selectedIds($request->getParsedBody());

        if ($selected === []) {
            $this->flash->error('Select at least one call to download.');

            return $this->back($storeId, $source, $date);
        }

        try {
            $company = $this->company->forStore($storeId);
        } catch (RecordingCompanyMissing $e) {
            // No code, no download — for every call, not just the first. Falling back to another value
            // would build a well-formed request for somebody else's audio.
            $this->flash->error($e->getMessage());

            return $this->back($storeId, $source, $date);
        }

        [$calls, $problem] = $this->callsOn($storeId, $source, $date);

        if ($problem !== null) {
            $this->flash->error($problem);

            return $this->back($storeId, $source, $date);
        }

        $wanted = [];

        foreach ($calls as $call) {
            if (in_array($call->callSessionId, $selected, true)) {
                $wanted[] = $call;
            }
        }

        if ($wanted === []) {
            $this->flash->error(sprintf(
                'None of the selected calls are in this store\'s list for %s. The page may be out of '
                    . 'date — load the calls again.',
                $date,
            ));

            return $this->back($storeId, $source, $date);
        }

        $this->request($storeId, $company, $wanted);

        return $this->back($storeId, $source, $date);
    }

    /**
     * Write the batch and its items, and report what actually happened.
     *
     * A call already asked for is skipped by the unique key rather than refused, so pressing the button
     * twice is safe and says so — a count of what was already here is a more useful answer than an
     * error about duplicates.
     *
     * @param non-empty-list<CallSummary> $calls
     */
    private function request(int $storeId, string $company, array $calls): void
    {
        $now = $this->clock->now();

        $batchId = $this->imports->createBatch(
            $storeId,
            'MANUAL',
            $this->currentAdmin->get()->id(),
            // Recorded because the column is not nullable. Nothing reads it for a download-only row —
            // see the class docblock.
            $this->providerDefault->current(),
            // Never. AI audio is a reading of a transcript, and this page has not asked for one.
            false,
            $company,
            $now,
            CallImportMode::DownloadOnly,
        );

        $asked = 0;
        $already = 0;

        foreach ($calls as $call) {
            $callDate = $call->derivedDate();

            if ($callDate === null) {
                // Filtered out already — a call reaches here only if its date could be read — but the
                // type says nullable and a silent '' would become a malformed fetch.
                ++$already;

                continue;
            }

            $created = $this->imports->queueCall(
                $batchId,
                $storeId,
                $call->callSessionId,
                $call->callTime,
                $callDate,
                $call->orderId === '' ? null : $call->orderId,
                // All three, every time. Which of them this merchant actually produces is the
                // provider's answer to give, and a 404 on caller or callee is recorded as "not
                // available" rather than guessed at here.
                RecordingChannel::all(),
                $now,
            );

            $created > 0 ? ++$asked : ++$already;
        }

        $this->flash->success($this->summary($asked, $already, count($calls)));
    }

    /**
     * One line, and the table says the rest.
     *
     * This used to carry three sentences explaining that recordings arrive one at a time, where they
     * appear, and that nothing is transcribed. All of it was true and none of it belonged in a banner:
     * it described, in prose, a process the reader is about to watch happen in the rows below. A message
     * that explains what a live view is already showing is a message people stop reading.
     *
     * So it states what was done and stops. The per-call progress underneath is what explains it.
     */
    private function summary(int $asked, int $already, int $total): string
    {
        if ($asked === 0) {
            return sprintf('Already requested — all %d selected call(s) were asked for earlier.', $total);
        }

        $message = sprintf(
            'Download started for %d %s.',
            $asked,
            $asked === 1 ? 'call' : 'calls',
        );

        return $already > 0
            ? $message . sprintf(' %d had already been requested.', $already)
            : $message;
    }

    /**
     * The calls for one day, straight from the provider — the only list this action trusts.
     *
     * `$date` is the day the page was showing, filtered with the SAME call the page itself uses
     * ({@see TodayCallFilter::onDate()}), so this intersects against exactly the rows the operator saw.
     *
     * @return array{list<CallSummary>, ?string}
     */
    private function callsOn(int $storeId, string $source, string $date): array
    {
        // The same gate the page uses, applied to the list this action actually trusts — otherwise the
        // fixtures would list calls that this then refused as unknown.
        if (FixtureAvailability::isRequested($source)) {
            return [$this->today->onDate($this->fixtures->today($this->clock->now()), $date), null];
        }

        $request = LatestCallsRequest::fromStrings((string) $storeId, (string) self::LIMIT);

        if ($request === null) {
            return [[], 'That store cannot be used to look up calls.'];
        }

        try {
            $result = $this->probe->latestCalls($request);
        } catch (Throwable) {
            return [[], 'The recording service could not be reached, so nothing was requested.'];
        }

        if (!$result->diagnosis->isSuccess()) {
            return [[], $result->diagnosis->headline . ' Nothing was requested.'];
        }

        return [$this->today->onDate($result->calls, $date), null];
    }

    /**
     * The posted checkboxes, as a list of plain call session ids.
     *
     * Shape only — digits, bounded. Whether any of them is a real call is settled by the intersection
     * above, which is the check that matters; this just keeps obvious rubbish out of an `IN` clause.
     *
     * @return list<string>
     */
    private function selectedIds(mixed $body): array
    {
        if (!is_array($body) || !array_key_exists('calls', $body) || !is_array($body['calls'])) {
            return [];
        }

        $ids = [];

        /** @var mixed $value */
        foreach ($body['calls'] as $value) {
            if (is_string($value) && preg_match('/\A\d{1,20}\z/', $value) === 1) {
                $ids[] = $value;
            }
        }

        return $ids;
    }

    private function positiveInt(string $raw): ?int
    {
        if (preg_match('/\A\d{1,9}\z/', $raw) !== 1) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    /** Back to the page, with the store still chosen and its calls reloaded. */
    private function back(?int $storeId, string $source = '', string $date = ''): ResponseInterface
    {
        return $this->redirect->afterPost(
            'order58.call-recordings',
            $storeId === null
                ? []
                // `source` is carried back so a local fixture session does not silently fall through to
                // the live API on the redirect, and `date` so the page returns to the day being worked
                // on. An empty date is omitted rather than sent as ''.
                : ['store' => $storeId, 'load' => '1']
                + ($source === '' ? [] : ['source' => $source])
                + ($date === '' ? [] : ['date' => $date]),
        );
    }

    /**
     * The `YYYY-MM-DD` the form posted, or today.
     *
     * Deliberately the same rule as the page's own GET field, including the reconstruction check that
     * rejects `2026-02-30` — which matches any reasonable pattern and is not a day.
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
}
