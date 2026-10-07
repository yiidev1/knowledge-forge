<?php

declare(strict_types=1);

namespace App\OrderTesting\Console;

use App\OrderTesting\Application\DemoOrderDirectory;
use App\OrderTesting\Application\DemoOrderSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;

use function count;
use function is_string;
use function max;
use function str_contains;

/**
 * Imports Order58 demo orders from the configured directory.
 *
 * ## Manual, by decision
 *
 * **Nothing schedules this.** Demo-order import is a manual step the client has chosen to keep manual:
 * an administrator finishes a test in Order58, comes back to Knowledge Forge and presses "Sync demo
 * orders" on `/order-testing`. This command is the same work from a shell, for server-side and
 * debugging use — it is not here for a scheduler to call, and no cron entry, systemd timer or worker
 * runs it.
 *
 * Both routes go through {@see \App\OrderTesting\Application\DemoOrderSyncService}, so whichever one
 * is used, it is one implementation under one lock.
 *
 * ## A missing directory is not a failure
 *
 * On a machine that has never produced a demo order there is nothing to read, so saying so and
 * exiting `OK` is the honest answer rather than a fault somebody then goes looking for. The one thing
 * that *is* reported as a fault is the directory being configured as, or inside, the live-order
 * directory — see {@see DemoOrderDirectory}.
 *
 * ## One at a time
 *
 * `flock` on its own file, non-blocking: a run that overlaps one already in progress — the button on
 * `/order-testing`, or a second shell — exits rather than scanning the same files twice. Its own file,
 * never shared with another command's, because sharing a lock is how every run of both ends up
 * skipping.
 */
#[AsCommand(
    name: 'kf:order-testing:import-demo-orders',
    description: 'Imports Order58 demo/test orders from the configured demo-order directory.',
)]
final class ImportDemoOrdersCommand extends Command
{
    public function __construct(
        private readonly DemoOrderSyncService $sync,
        private readonly DemoOrderDirectory $directory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Stop after this many files, so one pass stays bounded.',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report what the directory holds and whether it may be read, without importing anything.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = $this->directory->resolve();

        if (!$status->isUsable()) {
            // A directory that is missing is a quiet, expected state; one that points at live orders is
            // a configuration error somebody must fix, and it is the only reason this command fails.
            $problem = (string) $status->problem;

            if (str_contains($problem, 'LIVE order directory')) {
                $io->error($problem);

                return ExitCode::CONFIG;
            }

            $io->note($problem);

            return ExitCode::OK;
        }

        $io->text('Demo order directory: ' . (string) $status->path);

        if ($input->getOption('dry-run') === true) {
            $files = $this->directory->filesIn((string) $status->path);
            $io->success('Readable. ' . count($files) . ' demo order file(s) match the expected name shape.');

            return ExitCode::OK;
        }

        /** @var mixed $limitOption */
        $limitOption = $input->getOption('limit');
        $limit = is_string($limitOption) ? max(1, (int) $limitOption) : null;

        // The lock, and the import inside it, belong to the service — the same one the "Sync demo
        // orders" button on /order-testing calls. Two copies of the flock dance would be two chances
        // to disagree about which file is the lock, and the failure mode of disagreeing is somebody
        // at a shell and an administrator on the page importing the same directory at once.
        $outcome = $this->sync->sync($limit);

        if ($outcome->busy) {
            $io->note('Another import is already running; this pass does nothing.');

            return ExitCode::OK;
        }

        $report = $outcome->report;

        if ($report === null) {
            // The directory was re-checked inside the service and has become unusable since the
            // report above. Rare, and worth saying rather than claiming a run that did not happen.
            $io->error((string) $outcome->problem);

            return ExitCode::IOERR;
        }

        $io->text($report->summary());

        foreach ($report->refusals as $refusal) {
            $io->warning($refusal);
        }

        $io->success('Demo order import finished.');

        return ExitCode::OK;
    }
}
