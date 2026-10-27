<?php

declare(strict_types=1);

namespace App\Order58\Console;

use App\Order58\Application\CallRecordings\CallListParser;
use App\Order58\Application\CallRecordings\CallRecordingCatalog;
use App\Order58\Application\CallRecordings\CallRecordingReport;
use App\Order58\Domain\CallRecordings\CallRecordingRepositoryInterface;
use App\Order58\Domain\CallRecordings\OrderCalls;
use App\Shared\Domain\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

use function count;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Fills the call recording tables from the `calls` already stored in `order58_orders.payload_json`.
 *
 * Orders synchronised before the recording tables existed already carry their `calls` in the payload,
 * and the synchronisation would pick most of them up on its next run — but not an order that has left
 * the provider's rolling window, which is never sent again. This reads the stored payloads instead, so
 * it needs **no request to the rate-limited Case Order API**, and it touches no order and no line.
 *
 * Dry run by default: it reports what it would add and writes nothing until `--write` is given. Safe to
 * run any number of times, because it applies exactly the rules the synchronisation applies — a link
 * that is already stored is not written again, and nothing is ever removed.
 */
#[AsCommand(
    name: 'kf:order58:call-recordings:backfill',
    description: 'Store Case Order call sessions and WAV links from the payloads already saved (dry run unless --write).',
)]
final class BackfillCallRecordingsCommand extends Command
{
    private const PAGE = 500;

    public function __construct(
        private readonly CallRecordingRepositoryInterface $repository,
        private readonly CallRecordingCatalog $catalog,
        private readonly ClockInterface $clock,
        private readonly CallListParser $parser = new CallListParser(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('write', null, InputOption::VALUE_NONE, 'Store the links. Without it nothing is written.')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Only this store (its source id).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $write = (bool) $input->getOption('write');
        $store = $input->getOption('store');

        if ($store !== null && (!is_string($store) || preg_match('/\A[1-9]\d{0,18}\z/', $store) !== 1)) {
            $output->writeln('<error>--store must be a store source id.</error>');

            return ExitCode::USAGE;
        }

        $storeSourceId = $store === null ? null : (int) $store;
        $now = $this->clock->now();
        $total = new CallRecordingReport();
        $after = 0;

        do {
            $page = $this->repository->storedCallsPage($after, self::PAGE, $storeSourceId);
            $orders = [];

            foreach ($page as $stored) {
                $after = $stored->rowId;
                $parsed = $this->parser->parse($stored->calls);

                $orders[] = new OrderCalls(
                    $stored->storeSourceId,
                    $stored->sourceOrderId,
                    $parsed->calls,
                    $parsed->rejectedLinks,
                );
            }

            $this->add($total, $this->catalog->record($orders, $now, $write));
        } while (count($page) === self::PAGE);

        $output->writeln($write
            ? '<info>Call recording backfill complete.</info>'
            : '<comment>Dry run — nothing was written. Re-run with --write to store these.</comment>');
        $verb = $write ? 'added' : 'to add';

        $output->writeln(sprintf('  Orders read: %d', $total->ordersSeen));
        $output->writeln(sprintf('  Orders already up to date: %d', $total->ordersUnchanged));
        $output->writeln(sprintf('  Orders with changes: %d', $total->ordersChanged));
        $output->writeln(sprintf('  Call sessions %s: %d', $verb, $total->sessionsAdded));
        $output->writeln(sprintf('  Recording links %s: %d', $verb, $total->recordingsAdded));
        $output->writeln(sprintf('  Links replaced: %d', $total->recordingsReplaced));
        $output->writeln(sprintf('  Links ignored (not recording URLs): %d', $total->linksRejected));
        $output->writeln(sprintf('  Orders that failed: %d', $total->ordersFailed));

        return $total->ordersFailed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function add(CallRecordingReport $total, CallRecordingReport $page): void
    {
        $total->ordersSeen += $page->ordersSeen;
        $total->ordersUnchanged += $page->ordersUnchanged;
        $total->ordersChanged += $page->ordersChanged;
        $total->ordersMissing += $page->ordersMissing;
        $total->ordersFailed += $page->ordersFailed;
        $total->sessionsAdded += $page->sessionsAdded;
        $total->recordingsAdded += $page->recordingsAdded;
        $total->recordingsReplaced += $page->recordingsReplaced;
        $total->recordingsRelisted += $page->recordingsRelisted;
        $total->linksRejected += $page->linksRejected;
    }
}
