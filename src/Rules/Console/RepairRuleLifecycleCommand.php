<?php

declare(strict_types=1);

namespace App\Rules\Console;

use App\Rules\Application\RuleLifecycleRepairPreview;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

/**
 * Safe, read-only preview of stale rule lifecycle state. The permanent repair is the next successful full
 * Rules sync (markSeen restores active; post-sync projection reconcile materializes globals). This command
 * never writes and refuses --apply so operators cannot mass-reactivate correctly swept rules offline.
 */
#[AsCommand(
    name: 'kf:rules:repair-lifecycle',
    description: 'Dry-run preview of stale inactive / unmaterialized Order58 rules (never writes).',
)]
final class RepairRuleLifecycleCommand extends Command
{
    public function __construct(
        private readonly RuleLifecycleRepairPreview $preview,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report candidates only (default behaviour; always read-only).',
        );
        $this->addOption(
            'apply',
            null,
            InputOption::VALUE_NONE,
            'Rejected — use Sync Rules for the permanent repair path.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('apply')) {
            $output->writeln('<error>--apply is not supported.</error>');
            $output->writeln('Reactivation without an Order58 presence check would wrongly revive swept rules.');
            $output->writeln('Run a normal Rules sync (browser or kf:order58:schedule-rules); it self-heals.');

            return ExitCode::DATAERR;
        }

        $report = $this->preview->report();

        $output->writeln('<info>Order58 rule lifecycle repair preview (dry-run)</info>');
        $output->writeln(sprintf('Synced source rules: %d', $report['synced_source_rules']));
        $output->writeln(sprintf('Active sources: %d', $report['active_sources']));
        $output->writeln(sprintf('Inactive sources: %d', $report['inactive_sources']));
        $output->writeln(sprintf(
            'Stale inactive but present/active upstream (if still returned): %d',
            $report['stale_inactive_candidates'],
        ));
        $output->writeln(sprintf('Would reactivate (if still upstream on next full sync): %d', $report['would_reactivate_if_still_upstream']));
        $output->writeln(sprintf('Canonical rules currently inactive: %d', $report['canonical_inactive_with_inactive_sources']));
        $output->writeln(sprintf('Active canonical rules: %d', $report['active_canonical_rules']));
        $output->writeln(sprintf('Hidden rules KB present: %s', $report['hidden_rules_kb_present'] ? 'yes' : 'no'));
        $output->writeln(sprintf('Global documents live: %d', $report['global_documents_live']));
        $output->writeln(sprintf('Global documents missing for active canonicals: %d', $report['global_documents_missing_for_active']));
        $output->writeln('New / Changed / Unchanged: determined by the next Rules sync against Order58.');
        $output->writeln('Global documents to create/update/disable: determined by post-sync projection reconcile.');
        $output->writeln('<comment>No changes made</comment>');

        return ExitCode::OK;
    }
}
