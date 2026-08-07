<?php

declare(strict_types=1);

namespace App\Order58\Console;

use App\Order58\Application\DailySyncScheduler;
use App\Order58\Domain\Order58SyncType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

/**
 * The daily Order58 KNOWLEDGE scheduler — 02:00 America/New_York. Enqueue-only: it inserts one
 * `integration_sync_runs(type=knowledge)` per NY calendar day (idempotent, failure-safe, catch-up-aware) and
 * returns; the worker performs the actual paginated API sync. Independent of the rules schedule.
 *
 * Run it from cron under `CRON_TZ=America/New_York` at `0 2 * * *` (and, for downtime resilience, optionally
 * hourly — a catch-up pass only acts once 02:00 NY has passed and today is not yet scheduled).
 */
#[AsCommand(
    name: 'kf:order58:schedule-knowledge',
    description: 'Enqueue the daily Order58 Knowledge sync (02:00 America/New_York; idempotent per NY day; enqueue-only).',
)]
final class ScheduleKnowledgeSyncCommand extends Command
{
    private const HOUR = 2;

    public function __construct(
        private readonly DailySyncScheduler $scheduler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outcome = $this->scheduler->schedule(Order58SyncType::Knowledge, self::HOUR);
        $output->writeln('<info>Order58 Knowledge daily schedule: ' . $outcome->value . '</info>');

        return ExitCode::OK;
    }
}
