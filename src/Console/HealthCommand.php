<?php

declare(strict_types=1);

namespace App\Console;

use App\Shared\Application\Health\HealthChecker;
use App\Shared\Application\Health\HealthStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;

use function sprintf;

#[AsCommand(
    name: 'kf:health',
    description: 'Checks configuration, database, storage and pending migrations.',
)]
final class HealthCommand extends Command
{
    public function __construct(
        private readonly HealthChecker $checker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable output for monitoring.')
            ->setHelp(
                <<<'HELP'
                    Verifies that this installation can actually run.

                    Run it as BOTH identities after deployment:

                      ./yii kf:health                    # as the deployment user
                      sudo -u www-data ./yii kf:health   # as the web and cron user

                    The two runs must print the same config fingerprint. A mismatch means the tiers are
                    reading different configuration — the usual cause of "uploads queue but never
                    process". Nothing here contacts OpenAI; use kf:openai:ping for that.
                    HELP,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->checker->run();

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $report->isHealthy() ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Knowledge Forge health');

        $rows = [];

        foreach ($report->checks as $check) {
            $rows[] = [
                $this->badge($check->status),
                $check->name,
                $check->message . ($check->detail === null ? '' : "\n  " . $check->detail),
            ];
        }

        $io->table(['', 'Check', 'Result'], $rows);

        $io->writeln(sprintf('  Environment:        <info>%s</info>', $report->environment));
        $io->writeln(sprintf('  Config fingerprint: <info>%s</info>', $report->configFingerprint));
        $io->writeln('  (Run as the web/cron user too — the fingerprints must match.)');
        $io->newLine();

        if ($report->status() === HealthStatus::Failure) {
            $io->error('Health check failed. Fix the items marked FAIL above.');

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($report->status() === HealthStatus::Warning) {
            $io->warning('Healthy, with warnings.');

            return ExitCode::OK;
        }

        $io->success('All checks passed.');

        return ExitCode::OK;
    }

    private function badge(HealthStatus $status): string
    {
        return match ($status) {
            HealthStatus::Ok => '<fg=green>PASS</>',
            HealthStatus::Warning => '<fg=yellow>WARN</>',
            HealthStatus::Failure => '<fg=red>FAIL</>',
        };
    }
}
