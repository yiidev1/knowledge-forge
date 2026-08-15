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
 * The daily Order58 AGENTS scheduler — 01:00 America/New_York. Enqueue-only: it inserts one
 * `integration_sync_runs(type=agents)` per NY calendar day (idempotent, failure-safe, catch-up-aware) and
 * returns; the worker performs the actual paginated API sync into the `order58_agents` mirror.
 *
 * It runs FIRST, ahead of Knowledge (02:00) and Rules (03:00), because the agent mirror is what the fallback
 * agent login resolves an entered username against: that path refuses a row last synced more than
 * `ORDER58_VALIDATE_MAX_MIRROR_AGE_HOURS` ago, so a missing cadence here would turn into refused logins
 * rather than into merely stale reporting data. A separate schedule from the other two, with its own run
 * record and freshness — an agents failure never affects knowledge or rules, and vice versa.
 *
 * Run it from cron under `CRON_TZ=America/New_York` at `0 1 * * *`, or hourly: the scheduler only acts once
 * the local hour has passed and claims one reservation per NY date, so an hourly pass recovers a run missed
 * during downtime without ever firing early or twice.
 */
#[AsCommand(
    name: 'kf:order58:schedule-agents',
    description: 'Enqueue the daily Order58 Agents sync (01:00 America/New_York; idempotent per NY day; enqueue-only).',
)]
final class ScheduleAgentsSyncCommand extends Command
{
    private const HOUR = 1;

    public function __construct(
        private readonly DailySyncScheduler $scheduler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outcome = $this->scheduler->schedule(Order58SyncType::Agents, self::HOUR);
        $output->writeln('<info>Order58 Agents daily schedule: ' . $outcome->value . '</info>');

        return ExitCode::OK;
    }
}
