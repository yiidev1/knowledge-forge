<?php

declare(strict_types=1);

namespace App\OrderTesting\Console;

use App\OrderTesting\Application\DemoOrderDirectory;
use App\OrderTesting\Application\DemoOrderImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;

use function count;
use function fclose;
use function flock;
use function fopen;
use function is_resource;
use function is_string;
use function max;
use function str_contains;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * Imports Order58 demo orders from the configured directory.
 *
 * ## Why it is its own command and not a drainer
 *
 * It does small local reads and small writes — no network, no audio, no CPU to speak of — which is
 * exactly why it should run often and on its own schedule. Putting it inside `kf:worker:run` would make
 * how quickly a trainee sees their order depend on how long document processing happens to be taking.
 *
 * ## A missing directory is not a failure
 *
 * On a machine that has never produced a demo order there is nothing to read, and saying so and exiting
 * `OK` is the honest answer. A non-zero exit would make a scheduler report a fault every minute for a
 * condition nobody needs to fix. The one thing that *is* reported as a fault is the directory being
 * configured as, or inside, the live-order directory — see {@see DemoOrderDirectory}.
 *
 * ## One at a time
 *
 * `flock` on its own file, non-blocking: a run that overlaps a slow predecessor exits rather than
 * scanning the same files twice. Its own file, never shared with another command's, because sharing one
 * is how every run of both ends up skipping.
 */
#[AsCommand(
    name: 'kf:order-testing:import-demo-orders',
    description: 'Imports Order58 demo/test orders from the configured demo-order directory.',
)]
final class ImportDemoOrdersCommand extends Command
{
    public function __construct(
        private readonly DemoOrderImporter $importer,
        private readonly DemoOrderDirectory $directory,
        private readonly string $lockFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Stop after this many files, so one scheduled pass stays bounded.',
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

        $lock = @fopen($this->lockFile, 'c');

        if (!is_resource($lock)) {
            $io->error('Could not open the lock file: ' . $this->lockFile);

            return ExitCode::IOERR;
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $io->note('Another import is already running; this pass does nothing.');

            return ExitCode::OK;
        }

        try {
            /** @var mixed $limitOption */
            $limitOption = $input->getOption('limit');
            $limit = is_string($limitOption) ? max(1, (int) $limitOption) : null;

            $report = $this->importer->import($limit);

            $io->text($report->summary());

            foreach ($report->refusals as $refusal) {
                $io->warning($refusal);
            }

            $io->success('Demo order import finished.');

            return ExitCode::OK;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
