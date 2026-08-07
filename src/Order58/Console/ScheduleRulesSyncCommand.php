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
 * The daily Order58 RULES scheduler — 03:00 America/New_York. Enqueue-only: it inserts one
 * `integration_sync_runs(type=rules)` per NY calendar day (idempotent, failure-safe, catch-up-aware) and
 * returns; the worker performs the actual paginated API sync + classification/projection. A SEPARATE schedule
 * from Knowledge, with its own run record and freshness — a rules failure never affects knowledge, and vice versa.
 *
 * Run it from cron under `CRON_TZ=America/New_York` at `0 3 * * *`. It may safely enqueue even if the 02:00
 * knowledge run is still draining — the worker serializes the actual processing.
 */
#[AsCommand(
    name: 'kf:order58:schedule-rules',
    description: 'Enqueue the daily Order58 Rules sync (03:00 America/New_York; idempotent per NY day; enqueue-only).',
)]
final class ScheduleRulesSyncCommand extends Command
{
    private const HOUR = 3;

    public function __construct(
        private readonly DailySyncScheduler $scheduler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outcome = $this->scheduler->schedule(Order58SyncType::Rules, self::HOUR);
        $output->writeln('<info>Order58 Rules daily schedule: ' . $outcome->value . '</info>');

        return ExitCode::OK;
    }
}
