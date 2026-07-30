<?php

declare(strict_types=1);

namespace App\Worker\Console;

use App\Worker\Application\WorkerParams;
use App\Worker\Application\WorkerRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_numeric;
use function sprintf;

/**
 * Runs one pass of the background worker — provision knowledge bases, process documents, clean up remote
 * artifacts — then exits. Designed to be fired every minute by cron under `flock`; the runner takes its
 * own non-blocking lock too, so a manual run and a cron run never collide.
 *
 * Exit code mirrors {@see \App\Worker\Application\WorkerRunResult}: 0 healthy, 1 an item failed, 70 an
 * infrastructure fault — so cron mail / monitoring can distinguish "a document failed" from "the box is
 * broken".
 */
#[AsCommand(
    name: 'kf:worker:run',
    description: 'Runs one pass of the ingestion worker (provision, process, clean up).',
)]
final class RunWorkerCommand extends Command
{
    public function __construct(
        private readonly WorkerRunner $runner,
        private readonly WorkerParams $params,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximum items to handle per drainer this run. Defaults to DOCUMENT_WORKER_BATCH_SIZE.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $this->resolveLimit($input->getOption('limit'), $output);
        if ($limit === null) {
            return 64; // EX_USAGE — a bad --limit value.
        }

        $result = $this->runner->run($limit);

        if (!$result->lockAcquired) {
            $output->writeln('<comment>worker: another run holds the lock; skipping.</comment>');

            return $result->exitCode();
        }

        $output->writeln(sprintf(
            'worker: processed=%d failed=%d%s',
            $result->processed,
            $result->failed,
            $result->infraFailure ? ' (infrastructure failure)' : '',
        ));

        return $result->exitCode();
    }

    private function resolveLimit(mixed $option, OutputInterface $output): ?int
    {
        if ($option === null) {
            return $this->params->batchSize;
        }

        if (!is_numeric($option) || (int) $option < 1) {
            $output->writeln('<error>--limit must be a positive integer.</error>');

            return null;
        }

        return (int) $option;
    }
}
