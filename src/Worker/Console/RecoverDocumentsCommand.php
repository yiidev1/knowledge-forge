<?php

declare(strict_types=1);

namespace App\Worker\Console;

use App\Document\Application\Processing\RecoverStuckDocumentsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

use function sprintf;

/**
 * Returns documents stranded in `processing` — claimed by a worker that then died — back to `queued`, so
 * an operator can un-stick them without waiting for the next scheduled run to do it. The worker performs
 * the same recovery automatically each pass; this command is the manual escape hatch.
 */
#[AsCommand(
    name: 'kf:documents:recover',
    description: 'Requeues documents stuck in processing past the timeout.',
)]
final class RecoverDocumentsCommand extends Command
{
    public function __construct(
        private readonly RecoverStuckDocumentsService $recovery,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $recovered = $this->recovery->recover();

        $output->writeln(sprintf('Recovered %d stuck document(s).', $recovered));

        return ExitCode::OK;
    }
}
