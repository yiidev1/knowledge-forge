<?php

declare(strict_types=1);

namespace App\Order58\Web\Calls;

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
use App\Order58\Domain\CallImportRepositoryInterface;
use App\Order58\Domain\Exception\RecordingCompanyMissing;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function array_key_exists;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Queue the selected calls for import (POST /admin/order58/calls/sync).
 *
 * ## The browser's list of calls is never believed
 *
 * A checkbox posts a call session id, and a request can post any string at all. So this asks the
 * provider for today's calls again and **intersects**: an id that is not in that answer is dropped
 * without comment. That costs one extra request per Sync click and removes a whole class of problem —
 * a forged id would otherwise become a path segment in a fetch, and a stale tab's ids would queue work
 * for calls that no longer exist.
 *
 * The call's `callTime` is taken from that fresh answer too, never from the form. It decides the date
 * the recording is fetched with, and a date the browser could choose is a date that can be wrong.
 *
 * ## Nothing is downloaded here
 *
 * This writes rows and returns. The recordings are fetched by `kf:order58:import-recordings`, because a
 * web request that downloads twenty WAVs is a web request that times out halfway through and leaves
 * nobody able to say which ten arrived.
 *
 * ## `company` is resolved, not accepted
 *
 * {@see RecordingCompanyResolver} reads it from the selected store. A posted `company` is ignored
 * outright rather than validated — there is no value a browser could send that this should prefer to
 * the store's own, so there is nothing to validate.
 */
final readonly class SyncAction
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

        if ($storeId === null) {
            $this->flash->error('Choose a store first.');

            return $this->back(null, $source);
        }

        // The flag is a real gate, not a UI hint: a form submitted from a page rendered before it was
        // turned off must not queue work either.
        if (!$this->importEnabled) {
            $this->flash->error(
                'Recording import is turned off on this server (ORDER58_RECORDING_IMPORT_ENABLED). '
                . 'Nothing was queued.',
            );

            return $this->back($storeId, $source);
        }

        $selected = $this->selectedIds($request->getParsedBody());

        if ($selected === []) {
            $this->flash->error('Select at least one call to sync.');

            return $this->back($storeId, $source);
        }

        try {
            $company = $this->company->forStore($storeId);
        } catch (RecordingCompanyMissing $e) {
            // No code, no import — for every call, not just the first. Falling back to another value
            // would build a well-formed request for somebody else's audio.
            $this->flash->error($e->getMessage());

            return $this->back($storeId, $source);
        }

        $provider = $this->provider($form->string('transcription_provider'));

        if ($provider === null) {
            $this->flash->error('Choose one of the listed transcription providers.');

            return $this->back($storeId, $source);
        }

        // Presence is the yes: an unticked checkbox posts nothing, which is what makes "off" the
        // reliable default rather than something this form has to remember to say.
        $generateAiAudio = $form->has('generate_ai_audio');

        [$calls, $problem] = $this->todaysCalls($storeId, $source);

        if ($problem !== null) {
            $this->flash->error($problem);

            return $this->back($storeId, $source);
        }

        $queueable = [];

        foreach ($calls as $call) {
            if (in_array($call->callSessionId, $selected, true)) {
                $queueable[] = $call;
            }
        }

        if ($queueable === []) {
            $this->flash->error(
                'None of the selected calls are in this store\'s list for today. The page may be out of '
                . 'date — load the calls again.',
            );

            return $this->back($storeId, $source);
        }

        $this->queue($storeId, $company, $provider, $generateAiAudio, $queueable);

        return $this->back($storeId, $source);
    }

    /**
     * Write the batch and its items, and report what actually happened.
     *
     * A call already requested is skipped by the unique key rather than refused, so pressing Sync twice
     * is safe and says so — "3 already queued" is a more useful answer than an error about duplicates.
     *
     * @param non-empty-list<CallSummary> $calls
     */
    private function queue(
        int $storeId,
        string $company,
        string $provider,
        bool $generateAiAudio,
        array $calls,
    ): void {
        $now = $this->clock->now();

        $batchId = $this->imports->createBatch(
            $storeId,
            'MANUAL',
            $this->currentAdmin->get()->id(),
            $provider,
            $generateAiAudio,
            $company,
            $now,
        );

        $queued = 0;
        $skipped = 0;

        foreach ($calls as $call) {
            $date = $call->derivedDate();

            if ($date === null) {
                // Filtered out already — a call reaches here only if its date could be read — but the
                // type says nullable and a silent '' would become a malformed fetch.
                $skipped++;

                continue;
            }

            $created = $this->imports->queueCall(
                $batchId,
                $storeId,
                $call->callSessionId,
                $call->callTime,
                $date,
                $call->orderId === '' ? null : $call->orderId,
                // All three, every time. Which of them this merchant actually produces is the provider's
                // answer to give, and a 404 on caller or callee is recorded as "not available" rather
                // than guessed at here.
                RecordingChannel::all(),
                $now,
            );

            $created > 0 ? $queued++ : $skipped++;
        }

        $this->flash->success($this->summary($queued, $skipped, count($calls)));
    }

    private function summary(int $queued, int $skipped, int $total): string
    {
        if ($queued === 0) {
            return sprintf(
                'Nothing new to queue — all %d selected call(s) had already been requested.',
                $total,
            );
        }

        $message = sprintf(
            '%d call(s) queued. Recordings import in the background and appear on the store\'s '
                . 'Audio to Text page as they finish.',
            $queued,
        );

        return $skipped > 0
            ? $message . sprintf(' %d had already been requested.', $skipped)
            : $message;
    }

    /**
     * Today's calls, straight from the provider — the only list this action trusts.
     *
     * @return array{list<CallSummary>, ?string}
     */
    private function todaysCalls(int $storeId, string $source): array
    {
        // The same gate the page uses, applied to the list this action actually trusts — otherwise the
        // fixtures would list calls that the sync then refused as unknown.
        if (FixtureAvailability::isRequested($source)) {
            $now = $this->clock->now();

            return [$this->today->today($this->fixtures->today($now), $now), null];
        }

        $request = LatestCallsRequest::fromStrings((string) $storeId, (string) self::LIMIT);

        if ($request === null) {
            return [[], 'That store cannot be used to look up calls.'];
        }

        try {
            $result = $this->probe->latestCalls($request);
        } catch (Throwable) {
            return [[], 'The recording service could not be reached, so nothing was queued.'];
        }

        if (!$result->diagnosis->isSuccess()) {
            return [[], $result->diagnosis->headline . ' Nothing was queued.'];
        }

        return [$this->today->today($result->calls, $this->clock->now()), null];
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

    /** One of the providers this application offers, or null. */
    private function provider(string $posted): ?string
    {
        $choices = $this->providerDefault->choices();

        if ($posted === '') {
            return $this->providerDefault->current();
        }

        return array_key_exists($posted, $choices) ? $posted : null;
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
    private function back(?int $storeId, string $source = ''): ResponseInterface
    {
        return $this->redirect->afterPost(
            'order58.calls',
            $storeId === null
                ? []
                // `source` is carried back so a local fixture session does not silently fall through to
                // the live API on the redirect.
                : ['store' => $storeId, 'load' => '1'] + ($source === '' ? [] : ['source' => $source]),
        );
    }
}
