<?php

declare(strict_types=1);

namespace App\Rules\Console;

use App\Rules\Application\RuleReconciliationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

/**
 * Backfills/repairs the searchable projections of every active canonical rule so each becomes globally available
 * (and store-matched rules also get their store projection), without waiting for a re-sync.
 *
 * Safe to run any number of times: it only queues local generated-document work through the existing worker
 * pipeline (idempotent — no duplicate documents), NEVER calls OpenAI, and preserves raw mirror rows, audit
 * history, store links and every admin decision (ignored/disabled rules stay unavailable).
 */
#[AsCommand(
    name: 'kf:rules:reconcile-global',
    description: 'Queue global (and store) projections for every active Order58 rule (idempotent; no OpenAI).',
)]
final class ReconcileGlobalProjectionsCommand extends Command
{
    public function __construct(
        private readonly RuleReconciliationRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->runner->reconcileAllActive();

        $output->writeln('<info>Order58 rule projection reconciliation complete.</info>');
        $output->writeln(sprintf('  Active canonical rules reconciled: %d', $count));
        $output->writeln('  Global/store documents were queued for the worker; run kf:worker:run to index them.');

        return ExitCode::OK;
    }
}
