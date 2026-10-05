<?php

declare(strict_types=1);

namespace App\Order58\Console;

use App\Order58\Application\Orders\CallOrderDates;
use App\Order58\Application\Orders\OrderDateRange;
use App\Order58\Application\Orders\OrderSyncService;
use App\Order58\Application\RecordingImportProcessor;
use App\Order58\Domain\CallImportRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use DateTimeImmutable;
use App\Shared\Machine\ResourceAdmission;
use App\Shared\Machine\ResourceBudget;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Yiisoft\Yii\Console\ExitCode;

use function fclose;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function getmypid;
use function is_dir;
use function is_resource;
use function is_writable;
use function sprintf;
use function usleep;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * Fetches queued Order58 recordings and hands them to the transcription pipeline.
 *
 * ## Its own command, its own lock, its own schedule
 *
 * Not a drainer inside `kf:worker:run`, for the reason the transcription worker is not one either: this
 * downloads megabytes over a third-party network and then waits on the audio pipeline, and running that
 * inside the shared worker would stall document processing and Order58 sync behind every recording.
 *
 * The lock file is **its own**, and that is load-bearing. Sharing the transcription worker's lock would
 * make the two mutually exclusive for no reason; sharing the cron wrapper's lock with the application's
 * own would make every run skip. Both mistakes have been made in this project before and are documented
 * in `docs/server/cron/knowledge-forge-audio-transcription`.
 *
 * ## One call at a time, one recording at a time within it
 *
 * No concurrency, ever. The provider is a third party with undocumented rate limits, and the pipeline
 * this feeds already runs one transcription at a time — fetching ten recordings in parallel would only
 * move the queue from here to there, while multiplying the chance of a 429.
 *
 * What a run does take is one **call**: the mixed recording and then that same call's two sides, one
 * after another, before exiting. A call is what a person selected, and splitting it across three timer
 * ticks added two scheduler intervals of latency to every click for no benefit — the provider is not
 * slow, the schedule was. Each channel is finished and its temporary file consumed or removed before
 * the next is claimed, so three sequential downloads peak at the same memory as one.
 *
 * It is still **not a drainer**: the next call waits for the next tick, and a run that is taking too
 * long declines to start another channel rather than risking the unit's timeout. See
 * {@see RecordingImportProcessor::processNextCall()}.
 *
 * ## It asks whether the machine can spare the room, before it claims anything
 *
 * A download is cheap — a tick was measured at 44 MB, about 92 MB while ffprobe runs — but cheap is not
 * free, and on a server with no swap there is nowhere for a bad moment to spill. So each pass asks
 * {@see ResourceAdmission} first, against a budget sized from that measurement rather than from the
 * transcription worker's much larger one. Deferring costs minutes on work that is not time-critical;
 * being killed by the OOM reaper costs a claimed row and a partial file.
 *
 * What it does **not** wait for is anything to do with whisper — no `pgrep`, no foreign project's
 * transcription lock. It runs no whisper, so waiting on one would defer ticks for no reason.
 *
 * A deferred pass leaves the queue exactly as it found it: nothing claimed, nothing failed, no attempt
 * consumed, and an exit code of 0 so the next timer tick simply tries again.
 */
#[AsCommand(
    name: 'kf:order58:import-recordings',
    description: 'Fetches queued Order58 call recordings and queues them for transcription.',
)]
final class ImportRecordingsCommand extends Command
{
    /** A claimed item older than this was left behind by a killed process. */
    private const STALE_AFTER_SECONDS = 900;

    /** Between empty polls, so a long-running invocation does not spin. */
    private const IDLE_SLEEP_SECONDS = 5;

    /** @var resource|null */
    private $lock = null;

    public function __construct(
        private readonly RecordingImportProcessor $processor,
        /**
         * Read-only here: which batches this run finished, so their orders can be fetched once.
         *
         * The recording rows themselves are written by the processor above; nothing in the order path
         * touches them.
         */
        private readonly CallImportRepositoryInterface $imports,
        /**
         * The same service the "Sync Order58 Orders" button runs, reused exactly as it stands —
         * chunking, deadline, hash comparison, named lock and all. Duplicating any of that would be a
         * second opinion about when an order has changed.
         */
        private readonly OrderSyncService $orderSync,
        private readonly ClockInterface $clock,
        private readonly ResourceAdmission $admission,
        private readonly ResourceBudget $budget,
        private readonly LoggerInterface $logger,
        private readonly string $lockFile,
        /**
         * Where a download lands, which is whatever `tempnam()` will use.
         *
         * Passed in rather than read here so the startup check can be tested: PHP caches
         * `sys_get_temp_dir()` for the life of the process, so a test cannot move it with `putenv()`. The
         * container resolves it with the same call the downloader makes, in the same process, so the two
         * cannot disagree about which directory is being checked.
         */
        private readonly string $temporaryDirectory,
        private readonly bool $enabled,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'once',
            null,
            InputOption::VALUE_NONE,
            'Process at most one call — up to three recordings — then exit. Intended for a timer or cron.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Off by default. Enabling recording import is a deliberate act on a server whose IP the client
        // has allowlisted, not something a deployment turns on by arriving.
        if (!$this->enabled) {
            $io->writeln('Order58 recording import is disabled (ORDER58_RECORDING_IMPORT_ENABLED).');

            return ExitCode::OK;
        }

        // A temporary directory that does not exist is not an error `tempnam()` reports — it silently
        // falls back to the system one. On a host whose /tmp is a different filesystem that turns the
        // hand-off rename into a failure, and on a tmpfs /tmp it puts the whole recording in RAM. Both
        // are worth refusing to start over, because both are invisible once running.
        if (!is_dir($this->temporaryDirectory) || !is_writable($this->temporaryDirectory)) {
            $io->error(sprintf(
                'The temporary directory "%s" does not exist or is not writable, so a download has '
                    . 'nowhere safe to land. Create it for this user, or clear TMPDIR.',
                $this->temporaryDirectory,
            ));

            return ExitCode::DATAERR;
        }

        if (!$this->acquireLock()) {
            // Not an error: the previous run is still going, which is exactly what the lock is for.
            $io->warning('Another Order58 recording import is already running.');

            return ExitCode::OK;
        }

        $once = (bool) $input->getOption('once');

        try {
            // Fixed before any work, so a batch settled by this run is recognised by it rather than by
            // the next one. Read once: the clock moving mid-run must not change which batches qualify.
            $runStartedAt = $this->clock->now();

            $recovered = $this->processor->recoverStuck(self::STALE_AFTER_SECONDS);

            if ($recovered > 0) {
                $io->writeln(sprintf('Recovered %d stranded import(s).', $recovered));
            }

            $processed = 0;

            while (true) {
                // Before the claim, never after. A pass that cannot afford the work must leave the queue
                // exactly as it found it — nothing claimed, nothing failed, no attempt spent — so the
                // next tick picks up the same item with the same budget.
                $decision = $this->admission->decide($this->budget);

                if (!$decision->admitted) {
                    $this->logger->info('The Order58 recording importer deferred a pass.', [
                        'reason' => 'order58_import_deferred',
                        'error_message' => (string) $decision->reason,
                    ]);
                    $io->writeln('  deferred due to system resources: ' . (string) $decision->reason);

                    if ($once) {
                        break;
                    }

                    usleep(self::IDLE_SLEEP_SECONDS * 1_000_000);

                    continue;
                }

                $handled = $this->processor->processNextCall();

                if ($handled > 0) {
                    $processed += $handled;
                } elseif ($once) {
                    break;
                } else {
                    usleep(self::IDLE_SLEEP_SECONDS * 1_000_000);
                }

                if ($once) {
                    break;
                }
            }

            $io->writeln(sprintf('Processed %d recording(s).', $processed));

            // After the recordings, never instead of them, and never inside the loop. See syncOrders().
            $this->syncOrders($runStartedAt, $io);
        } catch (Throwable $e) {
            // The processor already handles a failure of one item. Reaching here means something outside
            // that — a database gone away — so the run ends rather than looping against it.
            $this->logger->error('The Order58 recording importer stopped.', [
                'reason' => 'order58_import_worker_failed',
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);

            $io->error('The importer stopped: ' . $e->getMessage());

            return ExitCode::UNSPECIFIED_ERROR;
        } finally {
            $this->releaseLock();
        }

        return ExitCode::OK;
    }

    /**
     * Synchronise the Order58 orders of every call batch this run finished.
     *
     * ## Why here and not in the web request
     *
     * The Orders API is a POST to a third party with a configured timeout, and the response for a busy
     * store is tens of orders to map and upsert. "Sync Recordings" writes rows and returns; putting an
     * external call into it would reintroduce exactly the wait that every other part of this feature is
     * arranged to avoid. Nothing here runs in a web request.
     *
     * ## Why once per batch, not once per call or once per run
     *
     * {@see CallImportRepositoryInterface::settledBatchCalls()} returns a batch's calls in the single
     * run that settles it, so a fifty-call selection costs **one** request per business day rather than
     * fifty. The dates are then deduplicated again here, because fifty calls on one evening are one
     * day's orders.
     *
     * ## Why a failure here cannot cost a recording
     *
     * The recordings are already downloaded and their rows already written by the time this runs. An
     * Orders API that is down, refuses, or answers something unreadable is logged and the run still
     * reports success. The two are independent on purpose: an order that did not arrive can be fetched
     * again from the Orders page, while a recording lost to an unrelated failure cannot.
     *
     * A date with no orders at all is one of those failures rather than an empty list — the provider
     * answers without an `Orders` key and the client raises. It is logged at info rather than warning
     * for that reason: on a quiet day it is the normal answer, and a warning that fires routinely is a
     * warning nobody reads.
     *
     * Concurrency is the existing named lock inside {@see OrderSyncService}, which is also what stops
     * this colliding with an administrator pressing "Sync Order58 Orders" for the same day.
     */
    private function syncOrders(DateTimeImmutable $runStartedAt, SymfonyStyle $io): void
    {
        try {
            $calls = $this->imports->settledBatchCalls($runStartedAt);
        } catch (Throwable $e) {
            $this->logger->warning('Order58 order sync could not be scheduled after a recording run.', [
                'reason' => 'order58_order_sync_lookup_failed',
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);

            return;
        }

        if ($calls === []) {
            return;
        }

        // Grouped by store first: an account id is half of what the Orders API is asked, and two stores
        // settling in one run are two different questions.
        /** @var array<int, list<string>> $byStore */
        $byStore = [];

        foreach ($calls as $call) {
            $byStore[$call['storeSourceId']][] = $call['callTimeRaw'];
        }

        foreach ($byStore as $storeSourceId => $callTimes) {
            foreach (CallOrderDates::fromCallTimes($callTimes) as $date) {
                $this->syncOneDate($storeSourceId, $date, $io);
            }
        }
    }

    /**
     * One store, one business day, one call to the existing service — reused exactly as the manual
     * button uses it, including its chunking, deadline, hash comparison and named lock.
     */
    private function syncOneDate(int $storeSourceId, string $date, SymfonyStyle $io): void
    {
        $ranges = OrderDateRange::create($date, $date);
        $range = $ranges[0] ?? null;

        if (!$range instanceof OrderDateRange) {
            // Unreachable for a date this application derived, and refused rather than guessed at.
            return;
        }

        try {
            $summary = $this->orderSync->sync($storeSourceId, $range);
        } catch (Throwable $e) {
            // Never fatal to the run. The message carries no body and no header - see OrderDataFailed.
            $this->logger->info('Order58 orders were not synchronised for a finished call batch.', [
                'reason' => 'order58_order_sync_skipped',
                'store_source_id' => $storeSourceId,
                'business_date' => $date,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);

            $io->writeln(sprintf('  orders %s %s: not synchronised', $storeSourceId, $date));

            return;
        }

        // Counts only. No order id, no customer, no payload.
        $this->logger->info('Order58 orders synchronised after a call batch finished.', [
            'reason' => 'order58_order_sync_done',
            'store_source_id' => $storeSourceId,
            'business_date' => $date,
            'received' => $summary->received,
            'created' => $summary->created,
            'updated' => $summary->updated,
            'unchanged' => $summary->unchanged,
        ]);

        $io->writeln(sprintf(
            '  orders %s %s: %d received, %d created, %d updated, %d unchanged',
            $storeSourceId,
            $date,
            $summary->received,
            $summary->created,
            $summary->updated,
            $summary->unchanged,
        ));
    }

    /**
     * Non-blocking, and opened with `c` so the file is created without being truncated first.
     *
     * The pid is written for diagnostics only; nothing reads it. The file is never unlinked — removing a
     * lock file another process holds is how two workers end up believing they are alone.
     */
    private function acquireLock(): bool
    {
        $handle = @fopen($this->lockFile, 'c');

        if (!is_resource($handle)) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        @ftruncate($handle, 0);
        @fwrite($handle, (string) getmypid());

        $this->lock = $handle;

        return true;
    }

    private function releaseLock(): void
    {
        $handle = $this->lock;
        $this->lock = null;

        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
