<?php

declare(strict_types=1);

namespace App\AudioToText\Console;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\Tts\GeneratedAudioStorage;
use App\AudioToText\Application\Tts\TtsGenerationService;
use App\AudioToText\Application\Tts\TtsRenditionGenerator;
use App\AudioToText\Application\Tts\TtsScriptBuilder;
use App\AudioToText\Application\WorkerAdmissionGuard;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Domain\Tts\TtsException;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsRendition;
use App\AudioToText\Domain\Tts\TtsRenditionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Yiisoft\Yii\Console\ExitCode;

use function count;
use function fclose;
use function flock;
use function fopen;
use function ftruncate;
use function function_exists;
use function fwrite;
use function getmypid;
use function is_resource;
use function microtime;
use function pcntl_async_signals;
use function pcntl_signal;
use function sprintf;
use function time;
use function usleep;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use const SIGINT;
use const SIGTERM;

/**
 * The only place text-to-speech runs. **A separate worker from transcription, on purpose.**
 *
 * ## Why not just add this to `kf:audio:worker`
 *
 * That worker is run by a systemd timer every two minutes with `--once`, and `--once` means *one job per
 * tick*. Generating AI audio there would make a text-to-speech request consume a transcription slot: the
 * moment anybody used this feature, the rate at which recordings turn into text would halve. Server load
 * is a hard constraint here, and so is not slowing down the thing this whole module exists to do.
 *
 * The two also do not compete for anything. Transcription is CPU-bound — one core held for ninety
 * seconds by whisper.cpp — while this is network-bound, waiting on HTTPS, with a single short ffmpeg
 * call at the end. They can run side by side without either noticing.
 *
 * ## Its own lock, which is not a detail
 *
 * `tts-worker.lock`, never `worker.lock`. Sharing one would produce the worst of both arrangements: a
 * text-to-speech tick would block the transcriber it was built to stay out of the way of, and every
 * transcription tick would make this one skip.
 *
 * ## What it deliberately does not do
 *
 * - **It does not take `ForeignLockGuard`.** That lock coordinates with other projects on this machine
 *   that also run Whisper. This runs no Whisper, so waiting on it would defer ticks for no reason.
 * - **It does not write `audio_worker_heartbeat`.** That table is single-row by construction, with a
 *   `CHECK (id = 1)` in its migration. Writing it here would make `WorkerHealthService` and the worker
 *   status strip report text-to-speech ticks as transcription ticks, quietly corrupting an admin surface
 *   that is currently correct.
 * - **It does not read `AudioToTextSettings::problems()`.** That method gates the transcription queue; a
 *   mistyped voice name has no business stopping every transcription on the machine. Readiness here is
 *   {@see \App\AudioToText\Application\Settings\TtsSettings::problems()} alone.
 *
 * It *does* take {@see WorkerAdmissionGuard}, because a machine under memory or load pressure should not
 * have a second worker cheerfully adding to it just because its own work is cheap.
 */
#[AsCommand(
    name: 'kf:audio:tts-worker',
    description: 'Generates queued AI training audio, one rendition at a time, on this machine only.',
)]
final class AudioTtsWorkerCommand extends Command
{
    private const HOUSEKEEPING_INTERVAL_SECONDS = 300;
    private const SLEEP_SLICE_MICROSECONDS = 250000;

    /**
     * How long a rendition may sit in GENERATING before it is presumed abandoned.
     *
     * Generously longer than any real generation: a long call is a few dozen sequential HTTP requests,
     * each bounded by `DEEPGRAM_TTS_TIMEOUT`. Recovering a run that was merely slow would mark a
     * perfectly good generation failed while it was still working.
     */
    private const ABANDONED_AFTER_SECONDS = 3600;

    /** Work files older than this belong to a run that is over, whatever its row says. */
    private const WORK_FILE_TTL_SECONDS = 7200;

    private bool $stopping = false;

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private readonly TtsRenditionRepositoryInterface $renditions,
        private readonly TranscriptionJobRepositoryInterface $jobs,
        private readonly TtsRenditionGenerator $generator,
        private readonly TtsScriptBuilder $scripts,
        private readonly TtsGenerationService $generation,
        private readonly GeneratedAudioStorage $storage,
        private readonly WorkerAdmissionGuard $admission,
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
            'Generate at most one queued rendition, then exit. Intended for a systemd timer, cron, and tests.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $once = (bool) $input->getOption('once');

        if (!$this->acquireLock()) {
            $io->warning('Another AI audio worker is already running.');

            // Deliberately successful: this is the guarantee working, not a fault. A supervisor must not
            // treat a correctly-refused duplicate as something to restart in a loop.
            return ExitCode::OK;
        }

        try {
            if (!$this->reportConfiguration($io)) {
                return ExitCode::DATAERR;
            }

            $this->listenForShutdownSignals($io);

            $io->writeln(sprintf(
                '<info>AI audio worker started (pid %d, %s, %s output).</info>',
                (int) getmypid(),
                $once ? 'single rendition' : 'continuous',
                $this->settings->tts->outputFormat->value,
            ));

            // Zero, so housekeeping runs on the very first pass — which is what makes a `--once` tick
            // under a timer also do the cleanup, rather than leaving it to a long-running worker nobody
            // is running.
            $lastHousekeeping = 0;

            while (!$this->stopping) {
                if ((time() - $lastHousekeeping) >= self::HOUSEKEEPING_INTERVAL_SECONDS) {
                    $this->housekeeping($io);
                    $lastHousekeeping = time();
                }

                $decision = $this->admission->decide();

                if (!$decision->admitted) {
                    $io->writeln('  deferred: ' . (string) $decision->reason);
                    $this->logger->info('AI audio worker deferred a tick.', [
                        'reason' => 'tts_worker_deferred',
                        'error_message' => (string) $decision->reason,
                    ]);

                    if ($once) {
                        break;
                    }

                    $this->sleep($this->settings->transcription->workerSleepSeconds);

                    continue;
                }

                $rendition = $this->renditions->claim();

                if ($rendition === null) {
                    if ($once) {
                        $io->writeln('No queued AI audio.');

                        break;
                    }

                    $this->sleep($this->settings->transcription->workerSleepSeconds);

                    continue;
                }

                $this->process($rendition, $io);

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
     * Generate one claimed rendition to a terminal state.
     *
     * The row is already GENERATING on entry, so every path out of here must end terminal — a row left
     * GENERATING is one {@see TtsRenditionRepositoryInterface::recoverStale()} has to rescue an hour
     * later, and until then the administrator watches "Generating" with no button that can help.
     */
    private function process(TtsRendition $rendition, SymfonyStyle $io): void
    {
        $startedAt = microtime(true);
        $job = $this->jobs->findById($rendition->jobId);

        if ($job === null) {
            $this->fail($rendition, 'The recording this audio belongs to is no longer available.', $io);

            return;
        }

        $io->writeln(sprintf(
            'Generating %s for %s (%s)',
            $rendition->outputType->value,
            $job->publicId,
            $job->originalFilename,
        ));

        try {
            $script = $this->scripts->build($job, $rendition->outputType);
            $hash = $this->generation->currentHash($job, $rendition->outputType);

            $result = $this->generator->generate($script, $rendition->outputType, $job->publicId, $hash);

            // Only the voices actually used are recorded, so a single-role file does not claim to have
            // been made with a voice that never spoke in it.
            $tts = $this->settings->tts;
            $usedCustomer = $rendition->outputType !== TtsOutputType::Agent;
            $usedAgent = $rendition->outputType !== TtsOutputType::Customer;

            $published = $this->renditions->markReady(
                $rendition->id,
                $rendition->attemptToken,
                $result->fileName,
                $hash,
                $this->generation->currentRenderKey($rendition->outputType),
                $result->fileBytes,
                $result->characterCount,
                $result->requestCount,
                $usedCustomer ? $tts->customerModel : null,
                $usedAgent ? $tts->agentModel : null,
            );

            if (!$published) {
                // This attempt was superseded while it was running — somebody re-queued the rendition,
                // and a newer one owns the row now. Its file is deleted rather than left pointing at
                // nobody; the newer attempt will write its own.
                $this->storage->remove($job->publicId, $result->fileName);
                $io->writeln('  superseded by a newer request; the generated file was discarded.');

                return;
            }

            // Only now, with the database pointing at the new file, is the one it replaced expendable.
            // Best effort: a generation that has already succeeded and already been paid for must not be
            // failed by a tidy-up.
            if ($rendition->fileName !== null && $rendition->fileName !== $result->fileName) {
                if (!$this->storage->remove($job->publicId, $rendition->fileName)) {
                    $this->logger->warning('Superseded AI audio could not be deleted.', [
                        'reason' => 'tts_superseded_file_retained',
                        'job_public_id' => $job->publicId,
                        'error_message' => $rendition->fileName,
                    ]);
                }
            }

            $io->writeln(sprintf(
                '  ready: %s (%.1fs of audio, %d characters in %d request%s, %.1fs elapsed)',
                $result->fileName,
                $result->durationSeconds,
                $result->characterCount,
                $result->requestCount,
                $result->requestCount === 1 ? '' : 's',
                microtime(true) - $startedAt,
            ));
        } catch (TtsException $e) {
            $this->logger->error('AI audio generation failed.', [
                'reason' => 'tts_generation_failed',
                'job_public_id' => $job->publicId,
                'error_message' => $e->technicalDetail(),
            ]);

            $this->fail($rendition, $e->getMessage(), $io);
        } catch (Throwable $e) {
            // Anything unanticipated still has to leave a terminal row behind. The message shown is
            // generic on purpose: an unexpected exception's text is not written for an administrator and
            // may name internals.
            $this->logger->error('AI audio generation failed unexpectedly.', [
                'reason' => 'tts_generation_crashed',
                'job_public_id' => $job->publicId,
                'error_message' => $e->getMessage(),
            ]);

            $this->fail($rendition, 'Generating this audio failed unexpectedly. Please try again.', $io);
        }
    }

    private function fail(TtsRendition $rendition, string $message, SymfonyStyle $io): void
    {
        $this->renditions->markFailed($rendition->id, $rendition->attemptToken, $message);
        $io->writeln('  failed: ' . $message);
    }

    /**
     * Cleanup nothing else performs.
     *
     * Neither of the transcription worker's sweeps looks outside `jobs/` and `recordings/`, so this tree
     * has no other collector. Wrapped whole: housekeeping failing must never stop renditions being
     * generated.
     */
    private function housekeeping(SymfonyStyle $io): void
    {
        try {
            $recovered = $this->renditions->recoverStale(self::ABANDONED_AFTER_SECONDS);

            if ($recovered > 0) {
                $io->writeln(sprintf('  recovered %d abandoned rendition(s).', $recovered));
                $this->logger->warning('Recovered abandoned AI audio renditions.', [
                    'reason' => 'tts_recovered_abandoned',
                    'count' => $recovered,
                ]);
            }

            $now = time();

            // Directories whose job row has gone. Mirrors sweepOrphanedRecordings(): the row is what
            // makes the audio meaningful, so its absence is the only thing that makes a directory
            // collectable.
            $orphans = $this->storage->sweepOrphans(
                fn(string $publicId): bool => $this->jobs->existsByPublicId($publicId),
                $this->settings->transcription->staleAfterSeconds,
                $now,
            );

            if ($orphans !== []) {
                $io->writeln(sprintf('  removed AI audio for %d deleted recording(s).', count($orphans)));
            }

            // Scratch from a run that was killed. Its job row is perfectly healthy, so the sweep above
            // will never touch it, and without this the files would accumulate indefinitely.
            $work = $this->storage->sweepWorkFiles(
                $this->renditions->generatingJobPublicIds(),
                self::WORK_FILE_TTL_SECONDS,
                $now,
            );

            if ($work > 0) {
                $io->writeln(sprintf('  removed %d abandoned work file(s).', $work));
            }
        } catch (Throwable $e) {
            $this->logger->warning('AI audio housekeeping failed.', [
                'reason' => 'tts_housekeeping_failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Readiness, and **only** text-to-speech readiness.
     *
     * Deliberately narrow: nothing here can stop the transcription queue, because nothing here is read by
     * it. The encoder check is a process launch and belongs at startup rather than mid-generation —
     * `libmp3lame` is a build-time option in ffmpeg, and discovering it missing after a call has been
     * synthesised means paying for audio that cannot be delivered.
     */
    private function reportConfiguration(SymfonyStyle $io): bool
    {
        foreach ($this->settings->tts->warnings() as $warning) {
            $io->writeln('<comment>note: ' . $warning . '</comment>');
        }

        try {
            $this->generator->assertReady();

            return true;
        } catch (TtsException $e) {
            $io->error('AI audio is not configured on this server: ' . $e->getMessage());
            $this->logger->error('AI audio configuration problem.', [
                'reason' => 'tts_configuration_invalid',
                'error_message' => $e->technicalDetail(),
            ]);

            return false;
        }
    }

    private function acquireLock(): bool
    {
        // 'c' creates without truncating; 'w' would blank a lock file another process is holding.
        $handle = @fopen($this->settings->transcription->ttsWorkerLockFile(), 'c');

        if (!is_resource($handle)) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        // Diagnostics only. Nothing reads this back; it is here so somebody looking at the file can tell
        // which process is holding it.
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
     * A generation in progress finishes and reaches a terminal status rather than being abandoned as a
     * GENERATING row — which matters more here than for transcription, because the requests it has
     * already made have already been billed.
     */
    private function listenForShutdownSignals(SymfonyStyle $io): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            $io->writeln('<comment>note: pcntl is unavailable, so shutdown will not be graceful.</comment>');

            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function () use ($io): void {
                $this->stopping = true;
                $io->writeln('Stopping after the current rendition.');
            });
        }
    }

    /** Sleeps in slices so a signal is noticed promptly rather than at the end of a long wait. */
    private function sleep(int $seconds): void
    {
        $slices = ($seconds * 1000000) / self::SLEEP_SLICE_MICROSECONDS;

        for ($i = 0; $i < $slices && !$this->stopping; $i++) {
            usleep(self::SLEEP_SLICE_MICROSECONDS);
        }
    }
}
