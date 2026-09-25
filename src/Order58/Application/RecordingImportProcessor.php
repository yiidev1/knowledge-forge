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
use Throwable;

use function in_array;
use function min;
use function unlink;

/**
 * Takes one queued recording channel as far as it can go, and records what happened.
 *
 * ## One item, one outcome, always written
 *
 * Every path through {@see processNext()} ends in a terminal status or a requeue. A claimed row that
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
     * Claim the next queued recording and see it through.
     *
     * @return bool whether there was anything to do
     */
    public function processNext(): bool
    {
        $item = $this->imports->claimNext($this->clock->now());

        if ($item === null) {
            return false;
        }

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

        return true;
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
