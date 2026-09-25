<?php

declare(strict_types=1);

namespace App\Order58\Console;

use App\Order58\Application\RecordingImportProcessor;
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
use function is_resource;
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
 * ## One recording at a time, on purpose
 *
 * No batching and no concurrency. The provider is a third party with undocumented rate limits, and the
 * pipeline this feeds already runs one transcription at a time — fetching ten recordings in parallel
 * would only move the queue from here to there, while multiplying the chance of a 429.
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
        private readonly LoggerInterface $logger,
        private readonly string $lockFile,
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
            'Process at most one recording, then exit. Intended for a timer or cron.',
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

        if (!$this->acquireLock()) {
            // Not an error: the previous run is still going, which is exactly what the lock is for.
            $io->warning('Another Order58 recording import is already running.');

            return ExitCode::OK;
        }

        $once = (bool) $input->getOption('once');

        try {
            $recovered = $this->processor->recoverStuck(self::STALE_AFTER_SECONDS);

            if ($recovered > 0) {
                $io->writeln(sprintf('Recovered %d stranded import(s).', $recovered));
            }

            $processed = 0;

            while (true) {
                $did = $this->processor->processNext();

                if ($did) {
                    $processed++;
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
