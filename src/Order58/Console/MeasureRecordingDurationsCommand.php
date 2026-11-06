<?php

declare(strict_types=1);

namespace App\Order58\Console;

use App\Order58\Application\RecordingStream\RecordingDurations;
use App\Order58\Application\RecordingStream\RecordingDurationSettings;
use App\Order58\Domain\CallRecordings\RecordingDurationRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;

use function fclose;
use function flock;
use function fopen;
use function is_numeric;
use function is_resource;
use function sprintf;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * Measures the length of Case Order call recordings that have none stored yet, once each.
 *
 * Bounded on purpose, so it can run on a timer beside everything else this server does:
 *
 *  - one HEAD request at a time (no audio is downloaded), through the same slot gate as playback, with
 *    `ORDER58_RECORDING_DURATION_PAUSE_MS` between requests;
 *  - at most `ORDER58_RECORDING_DURATION_BATCH` recordings, and no new request after
 *    `ORDER58_RECORDING_DURATION_RUN_SECONDS`;
 *  - its own lock file, so a second run started while one is going exits at once — and a claim on each
 *    row besides, so even two runs on two machines would not measure one recording twice;
 *  - failures retried with doubling delays, up to `ORDER58_RECORDING_DURATION_MAX_ATTEMPTS`; a run that
 *    finds every playback slot taken stops and leaves the rest for the next run.
 *
 * Nothing schedules it: the operator installs a timer when ready (see `docs/server/systemd`). It needs a
 * machine the recording service allowlists; elsewhere every request fails and is retried later, which is
 * harmless — an unmeasured recording is never hidden.
 */
#[AsCommand(
    name: 'kf:order58:call-recordings:measure-durations',
    description: 'Measures the length of Case Order call recordings that have none stored yet (one HEAD request each).',
)]
final class MeasureRecordingDurationsCommand extends Command
{
    /** @var resource|null */
    private $lock = null;

    public function __construct(
        private readonly RecordingDurations $durations,
        private readonly RecordingDurationRepositoryInterface $repository,
        private readonly RecordingDurationSettings $settings,
        private readonly LoggerInterface $logger,
        private readonly string $lockFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Measure at most this many recordings in this run (default: ORDER58_RECORDING_DURATION_BATCH).')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Print how many recordings are measured, pending and given up on, and exit without any request.')
            ->addOption('reset-exhausted', null, InputOption::VALUE_NONE, 'Give recordings that used every retry a fresh start, then exit without any request.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('status')) {
            $this->printBacklog($io);

            return ExitCode::OK;
        }

        if ((bool) $input->getOption('reset-exhausted')) {
            $io->writeln(sprintf('Reset %d recording(s) that had used every retry.', $this->repository->resetExhausted($this->settings->maxAttempts)));

            return ExitCode::OK;
        }

        $limit = null;
        $raw = $input->getOption('limit');

        if ($raw !== null) {
            if (!is_numeric($raw) || (int) $raw < 1 || (int) $raw > 1000) {
                $io->error('--limit must be a whole number from 1 to 1000.');

                return ExitCode::USAGE;
            }

            $limit = (int) $raw;
        }

        if (!$this->acquireLock()) {
            $io->writeln('Another duration measurement run is already going; nothing to do.');

            return ExitCode::OK;
        }

        try {
            $run = $this->durations->runBatch($limit);
        } finally {
            $this->releaseLock();
        }

        $io->writeln(sprintf(
            'Measured %d, retry later %d, given up %d, skipped %d%s.',
            $run->measured,
            $run->failed,
            $run->abandoned,
            $run->skipped,
            $run->stoppedBecause === null ? '' : ' — stopped early: ' . $run->stoppedBecause,
        ));

        $this->logger->info('Case Order recording durations measured.', [
            'reason' => 'order58_recording_durations_run',
            'measured' => $run->measured,
            'failed' => $run->failed,
            'abandoned' => $run->abandoned,
            'skipped' => $run->skipped,
        ]);

        return ExitCode::OK;
    }

    private function printBacklog(SymfonyStyle $io): void
    {
        $backlog = $this->repository->unmeasured($this->settings->maxAttempts);
        $io->writeln(sprintf(
            'Measured %d; waiting %d; given up %d (current recordings only).',
            $backlog->measured,
            $backlog->pending,
            $backlog->exhausted,
        ));
    }

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

        $this->lock = $handle;

        return true;
    }

    private function releaseLock(): void
    {
        $handle = $this->lock;
        $this->lock = null;

        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
