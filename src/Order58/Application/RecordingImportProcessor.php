<?php

declare(strict_types=1);

namespace App\Order58\Application;

use App\Integration\Order58Recording\ChannelDiagnosis;
use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Domain\CallImportItem;
use App\Order58\Domain\CallImportRepositoryInterface;
use App\Order58\Domain\Order58ImportStatus;
use App\Shared\Audio\AudioIngestionPortInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Log\SecretRedactor;
use Psr\Log\LoggerInterface;
use DateTimeImmutable;
use Throwable;

use function in_array;
use function min;
use function unlink;

/**
 * Takes one queued recording channel as far as it can go, and records what happened.
 *
 * ## One item, one outcome, always written
 *
 * Every path through {@see attempt()} ends in a terminal status or a requeue. A claimed row that
 * were left as `FETCHING` would be invisible to the next claim and would need the stale sweep to rescue
 * it, so the `finally` is not a tidiness measure — it is what stops a crash costing a recording.
 *
 * ## Which failures are worth another attempt
 *
 * The classification is {@see ChannelDiagnosis}'s, already written for the diagnostic page, rather than a
 * second reading of the same status codes. Two of its verdicts are treated specially:
 *
 * - **A 404 on caller or callee is not a failure.** Separated channels are generated only for the
 *   merchants on the client's list; for everyone else the file does not exist and never will. Recorded as
 *   `NOT_AVAILABLE`, terminal, never retried. The same 404 on the mixed channel *is* a failure, because
 *   every account is supposed to have a mixed recording.
 * - **A recording past the transcription limits is not a failure either.** It is a real call this
 *   application has not been given the authority to shorten, so it is recorded as `TOO_LARGE` with its
 *   measured size, and the limits can be reviewed against real calls later.
 */
final readonly class RecordingImportProcessor
{
    /** Ceiling on the backoff, matching the sync drainer's. */
    private const MAX_BACKOFF_SECONDS = 3600;

    private const BASE_BACKOFF_SECONDS = 60;

    /**
     * How long into a run a new channel may still be started.
     *
     * Derived from the unit's `TimeoutStartSec=300`, not guessed. One channel's worst case is the
     * provider's 60s read timeout plus ffprobe's 20s plus ingestion — call it 85s — so starting one at
     * 180s elapsed lands around 265s, inside the limit with room for the lock, the admission check and
     * the stale sweep. Three typical channels finish in a few seconds and never come near this; the
     * budget exists for the run where the provider has gone slow, which is exactly when being killed
     * mid-download would cost a claimed row and a partial file.
     */
    private const CALL_BUDGET_SECONDS = 180;

    /** Diagnoses that another attempt could plausibly resolve. */
    private const TRANSIENT = [
        ChannelDiagnosis::TIMEOUT,
        ChannelDiagnosis::CONNECTION_FAILED,
        ChannelDiagnosis::RATE_LIMITED,
        ChannelDiagnosis::SERVER_ERROR,
        // A WAV shorter than its header claims is a cut-short transfer, not a bad recording.
        ChannelDiagnosis::TRUNCATED,
    ];

    public function __construct(
        private CallImportRepositoryInterface $imports,
        private RecordingDownloader $downloader,
        private AudioIngestionPortInterface $ingestion,
        private SecretRedactor $redactor,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $maxAttempts,
    ) {}

    /**
     * Claim one call and see its channels through, one after another.
     *
     * ## One call, not one recording — and still not a drainer
     *
     * A person selects a *call*, and a call is up to three recordings. Taking strictly one row per run
     * meant each of those waited its own timer tick, so a single click took three scheduler intervals
     * to finish. That latency was pure scheduling: the provider is not slow, the schedule was.
     *
     * So a run takes the row it claimed and then that **same call's** remaining channels, sequentially,
     * and exits. It never looks at another call — the maximum unit of work for one invocation is one
     * call session and at most three channels, and the next call waits for the next tick exactly as it
     * did before. Nothing here downloads in parallel; each channel is fully finished, and its temporary
     * file consumed or removed, before the next is claimed.
     *
     * ## Why the time budget, and why it is checked before claiming
     *
     * The unit allows `TimeoutStartSec=300`. One channel's worst case is the provider read timeout (60s)
     * plus ffprobe's (20s) plus ingestion, so three in a row could come within about forty seconds of
     * being killed mid-download. {@see CALL_BUDGET_SECONDS} stops that, and it is checked **before** the
     * next sibling is claimed rather than during it: a channel this run declines to start is simply left
     * `pending`, which the next tick takes. A row is never claimed and then abandoned, so the stale
     * sweep has nothing new to rescue.
     *
     * @return int how many channels this run actually handled; 0 when there was nothing to do
     */
    public function processNextCall(): int
    {
        $item = $this->imports->claimNext($this->clock->now());

        if ($item === null) {
            return 0;
        }

        $startedAt = $this->clock->now();
        $this->attempt($item);
        $handled = 1;

        // The siblings of the call just claimed, and only those. Each is a fresh conditional claim, so
        // a channel another worker took in the meantime is skipped rather than fought over.
        while ($this->hasTimeForAnotherChannel($startedAt)) {
            $sibling = $this->imports->claimNextForCall(
                $item->storeSourceId,
                $item->callSessionId,
                $this->clock->now(),
            );

            if ($sibling === null) {
                break;
            }

            $this->attempt($sibling);
            ++$handled;
        }

        return $handled;
    }

    /**
     * One channel, taken as far as it can go, with its outcome written whatever happens.
     *
     * The try/catch is per channel on purpose: a fault on the mixed recording must not cost the two
     * sides their turn, and each row's attempt count and backoff are its own.
     */
    private function attempt(CallImportItem $item): void
    {
        try {
            $this->process($item);
        } catch (Throwable $e) {
            // A fault in this application rather than in the provider or the recording. Counted as an
            // attempt so a permanently broken item cannot spin, and logged with its class so the cause
            // is findable.
            $this->logger->error('An Order58 recording import failed unexpectedly.', [
                'reason' => 'order58_import_unexpected',
                'import_id' => $item->id,
                'call_session_id' => $item->callSessionId,
                'channel' => $item->channel->value,
                'error_class' => $e::class,
                'error_message' => $this->safe($e->getMessage()),
            ]);

            $this->giveUpOrRetry($item, 'import_error', $e->getMessage());
        }
    }

    /** Whether there is room inside the budget to start another channel from scratch. */
    private function hasTimeForAnotherChannel(DateTimeImmutable $startedAt): bool
    {
        return $this->clock->now()->getTimestamp() - $startedAt->getTimestamp() < self::CALL_BUDGET_SECONDS;
    }

    /** Return any item a killed worker left claimed, so it is picked up again rather than stranded. */
    public function recoverStuck(int $staleAfterSeconds): int
    {
        $now = $this->clock->now();

        return $this->imports->recoverStuck($now->modify('-' . $staleAfterSeconds . ' seconds'), $now);
    }

    private function process(CallImportItem $item): void
    {
        $download = $this->downloader->fetch($item);

        if (!$download->wasFetched()) {
            $this->recordFetchFailure($item, $download->diagnosis, $download->bytes);

            return;
        }

        $path = (string) $download->path;

        try {
            $outcome = $this->ingestion->ingestFile(
                $item->storeSourceId,
                $path,
                $this->downloader->fileNameFor($item->callSessionId, $item->channel),
                // The provider's channel, in the audio module's vocabulary. Caller stays CALLER; it is
                // never rewritten as a speaker role, because nothing here knows who spoke.
                $this->recordingTypeFor($item->channel),
                $item->orderId,
                $item->provider,
                $item->generateAiAudio,
                $item->requestedByAdminId,
                // The identity of the call, carried across the seam so the audio side can tell which
                // recordings belong together. Already in hand — it is what this whole item is keyed on.
                $item->callSessionId,
                // And when it happened, verbatim. The store page shows this beside the time the
                // recording was imported, which are two different facts and were being shown as one.
                $item->callTimeRaw,
                // The fork. A download-only batch stops here: the recording is stored and playable, and
                // nothing is asked of a speech provider. Somebody asks for the text later, per
                // recording, from the store page — or never.
                $item->mode->transcribes(),
            );
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }

        if ($outcome->wasQueued()) {
            $this->imports->markImported(
                $item->id,
                (string) $outcome->conversationPublicId,
                $download->bytes,
                null,
                $this->clock->now(),
            );

            return;
        }

        // The file was fetched and is a complete WAV, so a refusal here is about its size or its length:
        // the pipeline's limits, which this application is not authorised to raise on its own. Recorded
        // with the measured bytes as evidence, and not retried — it would be exactly as big next time.
        if (!$outcome->isTransient) {
            @unlink($path);

            $this->imports->markSettled(
                $item->id,
                Order58ImportStatus::TooLarge,
                'rejected_by_pipeline',
                $this->safe($outcome->firstProblem()),
                $download->bytes,
                null,
                $this->clock->now(),
            );

            return;
        }

        // The pipeline could not take it *now* — no disk, a full queue. The recording is fine.
        @unlink($path);
        $this->giveUpOrRetry($item, 'pipeline_unavailable', $outcome->firstProblem());
    }

    private function recordFetchFailure(CallImportItem $item, ChannelDiagnosis $diagnosis, int $bytes): void
    {
        // A merchant without separated channels: a fact about the merchant, not a fault, and nothing a
        // retry could change. The mixed channel gets no such grace — every account should have one.
        if (
            $diagnosis->kind === ChannelDiagnosis::NOT_FOUND
            && $item->channel !== RecordingChannel::Mixed
        ) {
            $this->imports->markSettled(
                $item->id,
                Order58ImportStatus::NotAvailable,
                $diagnosis->kind,
                'This merchant does not produce a separate ' . $item->channel->label() . ' recording.',
                $bytes > 0 ? $bytes : null,
                null,
                $this->clock->now(),
            );

            return;
        }

        if (in_array($diagnosis->kind, self::TRANSIENT, true)) {
            $this->giveUpOrRetry($item, $diagnosis->kind, $diagnosis->headline);

            return;
        }

        // Everything else — a 401, an allowlist refusal, a 200 that is not audio, an empty body — is a
        // condition retrying cannot fix. The advice is carried through because it is the only part an
        // administrator can act on ("ask the client to whitelist this IP").
        $this->imports->markSettled(
            $item->id,
            Order58ImportStatus::Failed,
            $diagnosis->kind,
            $this->safe($diagnosis->headline . ' ' . $diagnosis->advice),
            $bytes > 0 ? $bytes : null,
            null,
            $this->clock->now(),
        );
    }

    /**
     * One more attempt, or a final failure once the budget is spent.
     *
     * The backoff is `60 · 2^attempts`, capped at an hour — the same shape `IntegrationSyncDrainer` uses,
     * so two background features do not answer "how long before we try again" differently.
     */
    private function giveUpOrRetry(CallImportItem $item, string $code, string $message): void
    {
        $now = $this->clock->now();

        if ($item->attempts + 1 >= $this->maxAttempts) {
            $this->imports->markSettled(
                $item->id,
                Order58ImportStatus::Failed,
                $code,
                $this->safe($message),
                null,
                null,
                $now,
            );

            return;
        }

        $delay = min(self::MAX_BACKOFF_SECONDS, self::BASE_BACKOFF_SECONDS * (2 ** $item->attempts));

        $this->imports->requeue(
            $item->id,
            $now->modify('+' . $delay . ' seconds'),
            $code,
            $this->safe($message),
            $now,
        );
    }

    /**
     * The audio module's word for this channel.
     *
     * A deliberate, literal mapping rather than a shared enum: the two vocabularies belong to different
     * modules and are not allowed to name each other. They happen to agree on all three names, and the
     * one thing that must never happen is a caller becoming a "customer" — which cannot, because nothing
     * here produces a speaker role at all.
     */
    private function recordingTypeFor(RecordingChannel $channel): string
    {
        return match ($channel) {
            RecordingChannel::Mixed => 'MIXED',
            RecordingChannel::Caller => 'CALLER',
            RecordingChannel::Callee => 'CALLEE',
        };
    }

    /** Redacted and cut to the column, because this string is rendered on a page. */
    private function safe(string $message): string
    {
        return $this->redactor->redactAndTruncate($message, 1000);
    }
}
