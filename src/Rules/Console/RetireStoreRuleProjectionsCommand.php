<?php

declare(strict_types=1);

namespace App\Rules\Console;

use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\GeneratedDocumentRepositoryInterface;
use App\Order58\Application\SyncDocumentService;
use App\Shared\Domain\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

use function count;
use function sprintf;

/**
 * Fleet-wide retirement of store-rule projections (`order58_rule_store`) from every store knowledge base, so
 * store chat can no longer retrieve rule documents. Rule content stays fully available through the global Rule
 * Chat corpus (`order58_rule_global`); the canonical rule catalog and store links are never touched.
 *
 * Enqueue-only and safe by construction: for each store-rule document it flags the vector-store files for removal
 * and disables the document — exactly the path a disappeared source record already uses. The background worker
 * (`kf:worker:run`) performs the actual OpenAI removal. Idempotent: an already-retired document is skipped.
 *
 * Run with --dry-run FIRST to see the scope (documents and stores affected) before any destructive remote cleanup.
 */
#[AsCommand(
    name: 'kf:rules:retire-store-projections',
    description: 'Retire order58_rule_store documents from store KBs (enqueue-only; --dry-run to report first).',
)]
final class RetireStoreRuleProjectionsCommand extends Command
{
    public function __construct(
        private readonly GeneratedDocumentRepositoryInterface $documents,
        private readonly SyncDocumentService $sync,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report what would be retired without changing anything (no writes, no worker enqueue).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $locations = $this->documents->findLiveLocationsByType(DocumentSourceType::Order58RuleStore->value);

        $affectedStores = [];
        foreach ($locations as $location) {
            $affectedStores[$location->knowledgeBaseId] = true;
        }
        $docCount = count($locations);
        $storeCount = count($affectedStores);

        if ((bool) $input->getOption('dry-run')) {
            $output->writeln('<info>Dry run — no changes were made.</info>');
            $output->writeln(sprintf('  Live store-rule documents that would be retired: %d', $docCount));
            $output->writeln(sprintf('  Store knowledge bases affected: %d', $storeCount));
            $output->writeln('  Re-run without --dry-run to enqueue retirement; the worker then removes the files from OpenAI.');

            return ExitCode::OK;
        }

        $now = $this->clock->now();
        foreach ($locations as $location) {
            $this->sync->disableGenerated(
                $location->knowledgeBaseId,
                DocumentSourceType::Order58RuleStore,
                $location->sourceRef,
                $now,
            );
        }

        $output->writeln('<info>Store-rule projections retired.</info>');
        $output->writeln(sprintf('  Documents flagged for removal: %d across %d store knowledge bases', $docCount, $storeCount));
        $output->writeln('  Run kf:worker:run so the worker removes the files from the OpenAI vector stores.');

        return ExitCode::OK;
    }
}
