<?php

declare(strict_types=1);

namespace App\Worker\Console;

use App\Ai\Application\Operation\AiOperationReconciler;
use App\Worker\Application\WorkerParams;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_numeric;
use function sprintf;

/**
 * Resolves non-idempotent OpenAI operations whose outcome was left ambiguous (a create whose response
 * was lost to a timeout or reset). For each, the reconciler queries the provider by the deterministic
 * metadata we stamped on the request and either adopts the existing remote object — so no duplicate is
 * ever created — or reopens the operation for a fresh attempt.
 */
#[AsCommand(
    name: 'kf:ai:reconcile',
    description: 'Reconciles ambiguous non-idempotent OpenAI operations against the provider.',
)]
final class ReconcileCommand extends Command
{
    public function __construct(
        private readonly AiOperationReconciler $reconciler,
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
            'Maximum operations to reconcile this run. Defaults to DOCUMENT_WORKER_BATCH_SIZE.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $option = $input->getOption('limit');
        if ($option !== null && (!is_numeric($option) || (int) $option < 1)) {
            $output->writeln('<error>--limit must be a positive integer.</error>');

            return 64; // EX_USAGE
        }

        $limit = $option === null ? $this->params->batchSize : (int) $option;

        $resolved = $this->reconciler->reconcileBatch($limit);

        $output->writeln(sprintf('Reconciled %d operation(s).', $resolved));

        return 0;
    }
}
