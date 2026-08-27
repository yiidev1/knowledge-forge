<?php

declare(strict_types=1);

namespace App\AudioToText\Console;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\ForeignLockGuard;
use App\AudioToText\Application\QueuedAudioStorage;
use App\AudioToText\Application\Speaker\SpeakerSeparationService;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\ProcessingStage;
use App\AudioToText\Domain\SpeakerSeparationStatus;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Domain\WorkerHeartbeatRepositoryInterface;
use App\AudioToText\Domain\WorkerMode;
use App\AudioToText\Domain\WorkerProcessState;
use App\AudioToText\Infrastructure\AudioTranscriber;
use App\AudioToText\Infrastructure\AudioTranscriptionResult;
use App\AudioToText\Application\WorkerAdmissionGuard;
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
use function function_exists;
use function fwrite;
use function getmypid;
use function implode;
use function is_resource;
use function max;
use function mb_strlen;
use function microtime;
use function pcntl_async_signals;
use function pcntl_signal;
use function sprintf;
use function strtolower;
use function time;
use function usleep;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use const SIGINT;
use const SIGTERM;

/**
 * The only place in this application where Whisper and the diarizer run.
 *
 * Concurrency is guaranteed twice, and neither layer replaces the other:
 *
 * 1. **`flock` on `worker.lock`**, taken non-blocking and held for the entire process lifetime. A
 *    second worker acquires nothing, says so, and exits *successfully* — a correctly-refused duplicate
 *    is not a failure, and a supervisor should not restart it in a loop. `flock` rather than a database
 *    advisory lock because the kernel releases it however the process dies, so there is no stale lock
 *    to detect and no recovery code to get subtly wrong.
 * 2. **An atomic conditional claim**, so two workers never take the same row.
 *
 * The second alone is not enough: it would happily let worker A take job 1 while worker B takes job 2,
 * which is two Whisper processes on one machine. The lock is what makes the limit global.
 *
 * The lock file is never unlinked. Removing it races with a process that has already opened the same
 * path and is about to lock an inode that no longer has a name.
 */
#[AsCommand(
    name: 'kf:audio:worker',
    description: 'Processes queued Audio-to-Text jobs, one at a time, on this machine only.',
)]
final class AudioTranscriptionWorkerCommand extends Command
{
    private const HOUSEKEEPING_INTERVAL_SECONDS = 60;
    private const SLEEP_SLICE_MICROSECONDS = 250000;

    private bool $stopping = false;

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private readonly TranscriptionJobRepositoryInterface $jobs,
        private readonly WorkerHeartbeatRepositoryInterface $heartbeats,
        private readonly QueuedAudioStorage $storage,
        private readonly AudioTranscriber $transcriber,
        private readonly SpeakerSeparationService $separation,
        private readonly WorkerAdmissionGuard $admission,
        private readonly ForeignLockGuard $foreignLocks,
        private readonly AudioToTextSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'once',
            null,
            InputOption::VALUE_NONE,
            'Process at most one queued job, then exit. Intended for a systemd timer, cron, and tests.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $once = (bool) $input->getOption('once');
        $mode = $once ? WorkerMode::ONCE : WorkerMode::CONTINUOUS;

        try {
            $this->storage->prepareBaseDirectories();
        } catch (AudioTranscriptionException $e) {
            $io->error($e->getMessage());
            $this->logger->error('Audio worker could not prepare its directories. ' . $e->technicalDetail());

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$this->acquireLock()) {
            $io->warning('Another audio transcription worker is already running.');

            // Deliberately successful: this is the guarantee working, not a fault.
            return ExitCode::OK;
        }

        if (!$this->reportConfiguration($io)) {
            // The kernel would release the flock on exit anyway; releasing it here keeps the lifetime
            // explicit rather than relying on process teardown.
            $this->releaseLock();

            return ExitCode::DATAERR;
        }

        $this->listenForShutdownSignals($io);

        $io->writeln(sprintf(
            '<info>Audio transcription worker started (pid %d, %d thread%s, %s).</info>',
            (int) getmypid(),
            $this->settings->transcription->threads,
            $this->settings->transcription->threads === 1 ? '' : 's',
            $once ? 'single job' : 'continuous',
        ));

        // Zero, so housekeeping runs on the very first pass. That is what makes a --once tick under a
        // timer also do the cleanup, rather than leaving it to a long-running worker nobody is running.
        $lastHousekeeping = 0;

        try {
            while (!$this->stopping) {
                if ((time() - $lastHousekeeping) >= self::HOUSEKEEPING_INTERVAL_SECONDS) {
                    $this->housekeeping($io);
                    $lastHousekeeping = time();
                }

                $decision = $this->admission->decide();

                if (!$decision->admitted) {
                    $this->beat(WorkerProcessState::DEFERRED, $mode, true, 'DEFERRED');
                    $this->logger->info('Audio worker deferred a tick.', [
                        'reason' => 'audio_worker_deferred',
                        'error_message' => (string) $decision->reason,
                    ]);
                    $io->writeln('  deferred: ' . (string) $decision->reason);

                    if ($once) {
                        break;
                    }

                    $this->sleep($this->settings->transcription->workerSleepSeconds);

                    continue;
                }

                // Held across the whole job, not just the claim: the point is to stop another project
                // starting Whisper while ours is running, and a lock released at claim time would do
                // nothing about that.
                $blocked = $this->foreignLocks->acquire();

                if ($blocked !== null) {
                    $this->beat(WorkerProcessState::DEFERRED, $mode, true, 'DEFERRED');
                    $this->logger->info('Audio worker deferred a tick.', [
                        'reason' => 'audio_worker_foreign_lock',
                        'error_message' => $blocked,
                    ]);
                    $io->writeln('  deferred: ' . $blocked);

                    if ($once) {
                        break;
                    }

                    $this->sleep($this->settings->transcription->workerSleepSeconds);

                    continue;
                }

                try {
                    $job = $this->jobs->claimNextQueued();

                    if ($job === null) {
                        $this->beat(WorkerProcessState::IDLE, $mode, true, 'EMPTY');

                        if ($once) {
                            $io->writeln('No queued jobs.');

                            break;
                        }

                        $this->sleep($this->settings->transcription->workerSleepSeconds);

                        continue;
                    }

                    $this->beat(WorkerProcessState::BUSY, $mode, true, 'PROCESSED', $job->id);
                    $this->process($job, $io, $mode);
                } finally {
                    $this->foreignLocks->release();
                }

                if ($once) {
                    break;
                }
            }

            $io->writeln($this->stopping ? 'Stopped.' : 'Done.');

            return ExitCode::OK;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Runs one claimed job to a terminal state.
     *
     * The job is already PROCESSING on entry, so every path out of here must end terminal — a row left
     * PROCESSING is one the stale sweep has to clean up ten minutes later, and the person who uploaded
     * it watches a spinner until then.
     */
    private function process(TranscriptionJob $job, SymfonyStyle $io, WorkerMode $mode): void
    {
        $io->writeln(sprintf('Processing %s (%s)', $job->publicId, $job->originalFilename));
        $startedAt = microtime(true);

        try {
            $source = $this->storage->pathFor($job->publicId, $job->storedAudioPath);

            if ($source === null) {
                throw AudioTranscriptionException::uploadUnreadable('the queued audio is no longer on disk');
            }

            $separation = null;

            $result = $this->transcriber->transcribeFile(
                $source,
                function (string $stage) use ($job, $mode): void {
                    $parsed = ProcessingStage::tryFrom($stage);

                    if ($parsed !== null) {
                        $this->jobs->markStage($job->id, $parsed);
                    }

                    // Beat between stages too: a ninety-second transcription must not look like a dead
                    // worker to a page refreshing every two seconds.
                    $this->beat(WorkerProcessState::BUSY, $mode, false, null, $job->id);
                },
                function (string $wavPath, AudioTranscriptionResult $transcription) use (
                    $job,
                    $mode,
                    &$separation
                ): void {
                    // The transcript is committed here, before diarization is allowed to start. From
                    // this point a crash, an OOM kill or a MemoryMax enforcement can cost the speaker
                    // split but never the transcription itself.
                    $this->jobs->markTranscribed($job->id, $transcription->text, $transcription->language);

                    $this->jobs->markStage($job->id, ProcessingStage::DIARIZING);
                    $this->beat(WorkerProcessState::BUSY, $mode, false, null, $job->id);

                    $separation = $this->separation->separate($wavPath, $transcription->tokens);

                    $this->jobs->markStage($job->id, ProcessingStage::MAPPING_SPEAKERS);
                },
            );

            $this->jobs->markStage($job->id, ProcessingStage::SAVING);

            // The recording moves out of the temporary workspace into permanent storage *before* the row
            // is marked complete, so a row can never claim to have retained a file that is not there.
            $retained = $this->storage->retain($job->publicId, $job->storedAudioPath);

            // Defensive: separate() never throws, but if the callback somehow did not run there is still
            // a transcript to save, and saving it is more important than the split.
            $separation ??= $this->separation->separate('', []);

            $this->jobs->markCompleted($job->id, $separation, $retained);

            if ($separation->reason !== null) {
                $this->logger->info('Speaker separation did not complete.', [
                    'reason' => 'audio_speaker_separation_' . strtolower($separation->status->value),
                    'error_message' => $separation->reason,
                ]);
            }

            $io->writeln(sprintf(
                '  completed in %.1fs — %s, %d characters, speaker split: %s',
                microtime(true) - $startedAt,
                $result->language ?? 'unknown language',
                mb_strlen($result->text),
                $separation->status->label(),
            ));
        } catch (AudioTranscriptionException $e) {
            $this->jobs->markFailed($job->id, $e->getMessage());
            $this->logger->error('Audio transcription job failed.', [
                'reason' => 'audio_transcription_failed',
                'error_message' => $e->technicalDetail(),
            ]);
            $io->writeln('  failed: ' . $e->getMessage());
        } catch (Throwable $e) {
            $this->jobs->markFailed($job->id, AudioTranscriptionException::unexpected()->getMessage());
            $this->logger->error('Audio transcription job failed unexpectedly.', [
                'reason' => 'audio_transcription_unexpected',
                'error_message' => $e->getMessage(),
            ]);
            $io->writeln('  failed: unexpected error (see the log)');
        } finally {
            // The temporary workspace always goes. On success the recording has already been moved out
            // of it into permanent storage, so this deletes scaffolding rather than the recording; on
            // failure there is nothing worth keeping and the whole directory goes with it.
            $this->storage->remove($job->publicId);
        }
    }

    /**
     * Stale recovery, expiry and the orphan sweep.
     *
     * Wrapped whole in a catch: housekeeping is maintenance, and a failure in it must never take down a
     * worker that is otherwise transcribing perfectly well.
     */
    private function housekeeping(SymfonyStyle $io): void
    {
        try {
            foreach ($this->jobs->findStale($this->settings->transcription->staleAfterSeconds) as $job) {
                // The branch that makes committing the transcript early worth doing. A job that already
                // produced a transcript succeeded at the thing it was asked to do; only the optional
                // second stage died with the process, and that is what the separation status records.
                if ($job->transcript !== null) {
                    $this->jobs->markCompletedWithoutSeparation($job->id, SpeakerSeparationStatus::FAILED);
                    $this->logger->warning('Recovered a job that died during speaker separation.', [
                        'reason' => 'audio_separation_interrupted',
                        'error_message' => 'transcript preserved; speaker separation marked FAILED',
                    ]);
                    $io->writeln('  recovered stale job ' . $job->publicId . ' (transcript preserved)');
                } else {
                    $failure = AudioTranscriptionException::interrupted($this->settings->transcription->staleAfterSeconds);
                    $this->jobs->markFailed($job->id, $failure->getMessage());
                    $this->logger->warning('Recovered a stale transcription job.', [
                        'reason' => 'audio_transcription_interrupted',
                        'error_message' => $failure->technicalDetail(),
                    ]);
                    $io->writeln('  recovered stale job ' . $job->publicId);
                }

                // Never retried. A crash mid-Whisper may well have been the OOM killer, and an automatic
                // retry would simply reproduce it on a loop.
                $this->storage->remove($job->publicId);
            }

            // Only reached when AUDIO_TRANSCRIPTION_RETENTION_SECONDS is positive. At the default of 0
            // every conversation is kept indefinitely and this loop has nothing to iterate.
            foreach ($this->jobs->findExpired() as $job) {
                // Files first, then the row: the reverse order would lose the only record of which
                // directories belonged to the job if a delete failed halfway.
                $this->storage->remove($job->publicId);
                $this->storage->removeRetained($job->publicId);
                $this->jobs->delete($job->id);
                $io->writeln('  expired and removed ' . $job->publicId);
            }

            $swept = $this->storage->sweepOrphans(
                $this->jobs->activePublicIds(),
                $this->settings->transcription->staleAfterSeconds,
                time(),
            );

            foreach ($swept as $publicId) {
                $io->writeln('  removed orphaned workspace ' . $publicId);
            }

            // Retained recordings whose job row is gone. Narrow by construction: a recording is retained
            // *because* a row references it, so a missing row is what defines an orphan here. This can
            // never remove a recording that is still retained, whatever the retention setting is.
            $orphanedRecordings = $this->storage->sweepOrphanedRecordings(
                fn(string $publicId): bool => $this->jobs->existsByPublicId($publicId),
                $this->settings->transcription->staleAfterSeconds,
                time(),
            );

            foreach ($orphanedRecordings as $publicId) {
                $io->writeln('  removed orphaned recording ' . $publicId);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Audio transcription housekeeping failed.', [
                'reason' => 'audio_housekeeping_failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Heartbeat writes are advisory and must never be able to fail a job. The lock, not this table, is
     * what guarantees single-worker operation.
     */
    private function beat(
        WorkerProcessState $state,
        WorkerMode $mode,
        bool $tick,
        ?string $outcome = null,
        ?int $jobId = null,
    ): void {
        try {
            $this->heartbeats->beat($state, $mode, $tick, $outcome, $jobId);
        } catch (Throwable $e) {
            $this->logger->warning('Audio worker heartbeat could not be written.', [
                'reason' => 'audio_worker_heartbeat_failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validates the configuration once, at startup, and says what it found.
     *
     * The point is to turn a misconfiguration into a sentence naming the variable to fix, rather than
     * into a job that fails ten minutes later — or a queue that silently never moves. The commonest
     * cause of the latter is mundane and completely invisible from outside: another project's lock
     * directory is 0750 and owned by its own user, so a worker started as anyone else defers every
     * single tick without ever explaining why.
     *
     * Refusing to start is deliberate. A worker that runs with a broken configuration produces failed
     * jobs an operator then has to diagnose; a worker that will not start produces one clear message.
     *
     * @return bool false when the worker must not continue
     */
    private function reportConfiguration(SymfonyStyle $io): bool
    {
        foreach ($this->settings->warnings() as $warning) {
            $io->writeln('<comment>note: ' . $warning . '</comment>');
        }

        $problems = $this->settings->problems();

        if ($problems === []) {
            return true;
        }

        $io->error("The Audio-to-Text configuration is not usable:\n  - " . implode("\n  - ", $problems));

        foreach ($problems as $problem) {
            $this->logger->error('Audio-to-Text configuration problem.', [
                'reason' => 'audio_configuration_invalid',
                'error_message' => $problem,
            ]);
        }

        return false;
    }

    private function acquireLock(): bool
    {
        // 'c' creates without truncating; 'w' would blank a lock file another process is holding.
        $handle = @fopen($this->settings->transcription->workerLockFile(), 'c');

        if (!is_resource($handle)) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        // Diagnostics only. Nothing reads this back; it is here so that someone looking at the file can
        // tell which process is holding it.
        @ftruncate($handle, 0);
        @fwrite($handle, (string) getmypid());

        $this->lockHandle = $handle;

        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle === null) {
            return;
        }

        // Null the property before closing, so it never holds a closed handle.
        $handle = $this->lockHandle;
        $this->lockHandle = null;

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * SIGTERM and SIGINT set a flag; they do not exit.
     *
     * That distinction is the whole point: a running transcription finishes and reaches a terminal
     * status instead of being abandoned as a PROCESSING row for the stale sweep to find ten minutes
     * later. It is also why the systemd example uses `KillMode=mixed` — the default control-group kill
     * would signal `whisper-cli` as well and defeat this entirely.
     */
    private function listenForShutdownSignals(SymfonyStyle $io): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            $io->writeln(
                '<comment>pcntl is unavailable: Ctrl-C will stop the worker immediately rather than '
                . 'between jobs. Stale recovery cleans up after a hard kill.</comment>',
            );

            return;
        }

        pcntl_async_signals(true);

        $handler = function () use ($io): void {
            if ($this->stopping) {
                return;
            }

            $this->stopping = true;
            $io->writeln('<comment>Finishing the current job, then stopping…</comment>');
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    /** Sleeps in slices so a signal is noticed promptly rather than at the end of the interval. */
    private function sleep(int $seconds): void
    {
        $remaining = max(1, $seconds) * 1000000;

        while ($remaining > 0 && !$this->stopping) {
            usleep(self::SLEEP_SLICE_MICROSECONDS);
            $remaining -= self::SLEEP_SLICE_MICROSECONDS;
        }
    }
}
