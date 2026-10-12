<?php

declare(strict_types=1);

namespace App\Order58\Console;

use App\Order58\Application\Items\ItemSyncResult;
use App\Order58\Application\Items\OrderItemSyncInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;

use function is_string;
use function preg_match;
use function sprintf;

/**
 * Fetches ONE original order's items, from a shell.
 *
 * ## Why this exists alongside the button
 *
 * The same work as "Sync Order Items" on the orders listing and the comparison page, through the same
 * {@see OrderItemSyncInterface} — but runnable without a browser session, which is what a controlled
 * rollout needs. The intended use is exactly that: enable the feature, fetch one order, inspect what
 * was stored, and only then widen.
 *
 * ## It cannot become a backfill
 *
 * Both arguments are required and there is no "all", no range, no store-wide mode and no loop. One
 * invocation is one order and at most one provider request. That is a deliberate limit rather than an
 * omission: a command that could walk every order is a command that will eventually be pointed at all
 * of them by accident, and this endpoint costs one request per order.
 *
 * Retries are not this command's business either. A failure records its own backoff and the background
 * drainer picks it up when it is due; running this again immediately would simply spend another
 * attempt against a provider that has just refused.
 */
#[AsCommand(
    name: 'kf:order58:sync-order-items',
    description: 'Fetches the original line items for ONE Order58 order. One order, one request.',
)]
final class SyncOrderItemsCommand extends Command
{
    public function __construct(private readonly OrderItemSyncInterface $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('account-id', InputArgument::REQUIRED, 'The Order58 store account id')
            ->addArgument('order-id', InputArgument::REQUIRED, 'The source order id, as Order58 states it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $accountId = $this->positiveInt($input->getArgument('account-id'));
        $sourceOrderId = $this->positiveInt($input->getArgument('order-id'));

        if ($accountId === null || $sourceOrderId === null) {
            $io->error('Both ids must be positive whole numbers.');

            return ExitCode::DATAERR;
        }

        $io->writeln(sprintf('Account %d, order %d — one request.', $accountId, $sourceOrderId));

        // `manual: true`: the wider claim a person pressing the button gets, so an order the worker has
        // merely queued can be taken immediately rather than waited for.
        $outcome = $this->sync->syncOrder($accountId, $sourceOrderId, manual: true);

        return match ($outcome->result) {
            ItemSyncResult::Synced => $this->ok($io, sprintf('Synced — %d item(s) stored.', $outcome->itemCount)),
            // The provider answered and this order genuinely has no lines. A success, and comparable.
            ItemSyncResult::Empty => $this->ok($io, 'Synced — the provider reports no items for this order.'),
            ItemSyncResult::Unchanged => $this->ok($io, sprintf(
                'Already up to date — %d item(s), nothing rewritten.',
                $outcome->itemCount,
            )),
            ItemSyncResult::Busy => $this->warn($io, 'Already in progress elsewhere. No request was made.'),
            ItemSyncResult::Unavailable => $this->warn($io, (string) $outcome->error),
            ItemSyncResult::Failed => $this->failed($io, (string) $outcome->error),
        };
    }

    private function ok(SymfonyStyle $io, string $message): int
    {
        $io->success($message);

        return ExitCode::OK;
    }

    /** Neither done nor broken: nothing was attempted, and nothing about the order changed. */
    private function warn(SymfonyStyle $io, string $message): int
    {
        $io->warning($message);

        return ExitCode::OK;
    }

    private function failed(SymfonyStyle $io, string $message): int
    {
        $io->error('Not synced. Previously stored items were kept. ' . $message);

        return ExitCode::UNSPECIFIED_ERROR;
    }

    /** Digits only and bounded; anything else is refused rather than cast into a different number. */
    private function positiveInt(mixed $raw): ?int
    {
        return is_string($raw) && preg_match('/^[1-9][0-9]{0,18}$/', $raw) === 1 ? (int) $raw : null;
    }
}
