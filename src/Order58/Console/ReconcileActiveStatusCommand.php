<?php

declare(strict_types=1);

namespace App\Order58\Console;

use App\Order58\Application\ActiveStatusReconciler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

/**
 * Repairs stale Order58 store active status from the local snapshot (see {@see ActiveStatusReconciler}).
 *
 * Safe to run any number of times: it corrects only rows that are actually wrong, never calls Order58, and
 * preserves knowledge-base ids, vector stores, documents, conversations and every administrator
 * `agent_enabled` choice. Intended as the one-off repair for mirror rows an earlier sync wrote incorrectly.
 */
#[AsCommand(
    name: 'kf:order58:reconcile-active',
    description: 'Reconcile Order58 store active status from the local snapshot (repairs stale mirror rows).',
)]
final class ReconcileActiveStatusCommand extends Command
{
    public function __construct(
        private readonly ActiveStatusReconciler $reconciler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->reconciler->reconcile();

        $output->writeln('<info>Order58 active-status reconciliation complete.</info>');
        $output->writeln(sprintf('  Stores checked:              %d', $report->storesChecked));
        $output->writeln(sprintf('  Store rows corrected:        %d', $report->storesCorrected));
        $output->writeln(sprintf('  Knowledge bases corrected:   %d', $report->knowledgeBasesCorrected));
        $output->writeln(sprintf('  Skipped (no usable flag):    %d', $report->skippedInvalid));

        return ExitCode::OK;
    }
}
