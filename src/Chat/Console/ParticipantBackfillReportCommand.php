<?php

declare(strict_types=1);

namespace App\Chat\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Console\ExitCode;

use function count;
use function date;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function sprintf;

/**
 * Pre-flight report for typed participant_type + participant_id backfill (read-only).
 */
#[AsCommand(
    name: 'chat:participant-backfill-report',
    description: 'Reports conversation ownership and proposed participant_type/id backfill (read-only).',
)]
final class ParticipantBackfillReportCommand extends Command
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Aliases $aliases,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'write',
            null,
            InputOption::VALUE_OPTIONAL,
            'Write report path (default: runtime/backups/chat-participants-YYYYMMDD/backfill-report.txt)',
            'default',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $activeAdmins = $this->db->createCommand(
            'SELECT [[id]], [[username]] FROM {{%admin_users}} WHERE [[is_active]] = 1 ORDER BY [[id]]',
        )->queryAll();
        $activeCount = count($activeAdmins);

        $hasParticipantKey = (bool) $this->db->createCommand(
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'participant_key'",
        )->queryScalar();

        $hasTyped = (bool) $this->db->createCommand(
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'participant_type'",
        )->queryScalar();

        $sharedAdmin = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%conversations}} WHERE [[agent_admin_id]] IS NULL',
        )->queryScalar();
        $agentThreads = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%conversations}} WHERE [[agent_admin_id]] IS NOT NULL',
        )->queryScalar();
        $total = $sharedAdmin + $agentThreads;

        $select = 'SELECT [[id]], [[knowledge_base_id]], [[agent_admin_id]], [[title]]';
        if ($hasParticipantKey) {
            $select .= ', [[participant_key]]';
        }
        if ($hasTyped) {
            $select .= ', [[participant_type]], [[participant_id]]';
        }
        $rows = $this->db->createCommand(
            $select . ' FROM {{%conversations}} ORDER BY [[knowledge_base_id]], [[id]]',
        )->queryAll();

        $lines = [
            '=== chat:participant-backfill-report ===',
            'Generated: ' . date('c'),
            '',
            'ROOT CAUSE',
            'Admin chat used agent_admin_id IS NULL / participant_key=0 (shared).',
            'Logged-in admin_users.id was never stored. Agents already use agent_admin_id.',
            'Typed participant_type+participant_id avoids admin id N colliding with agent id N.',
            '',
            'ACTIVE ADMINS (' . $activeCount . ')',
        ];
        foreach ($activeAdmins as $admin) {
            $lines[] = sprintf('  id=%d username=%s', (int) $admin['id'], (string) $admin['username']);
        }

        $lines[] = '';
        $lines[] = 'COUNTS';
        $lines[] = 'conversations_total: ' . $total;
        $lines[] = 'shared_admin_threads (agent_admin_id IS NULL): ' . $sharedAdmin;
        $lines[] = 'agent_threads: ' . $agentThreads;
        $lines[] = 'participant_key column present: ' . ($hasParticipantKey ? 'yes' : 'no');
        $lines[] = 'participant_type column present: ' . ($hasTyped ? 'yes' : 'no');
        $lines[] = '';

        $canAuto = $sharedAdmin === 0 || $activeCount === 1;
        if ($sharedAdmin > 0 && $activeCount !== 1) {
            $lines[] = 'BACKFILL STATUS: STOP — shared admin threads exist but active admin count is not exactly 1.';
            $lines[] = 'Produce a manual mapping; do not run migrate:up until resolved.';
        } elseif ($sharedAdmin > 0 && $activeCount === 1) {
            $adminId = (int) $activeAdmins[0]['id'];
            $lines[] = 'BACKFILL STATUS: OK — will assign shared admin threads to admin participant_id=' . $adminId;
        } else {
            $lines[] = 'BACKFILL STATUS: OK — no shared admin threads to assign (or already typed).';
        }

        $lines[] = '';
        $lines[] = 'CONVERSATIONS (proposed type/id)';
        foreach ($rows as $row) {
            $agentAdminId = $row['agent_admin_id'] === null ? null : (int) $row['agent_admin_id'];
            if ($hasTyped && $row['participant_type'] !== null) {
                $type = (string) $row['participant_type'];
                $pid = (int) $row['participant_id'];
            } elseif ($agentAdminId !== null) {
                $type = 'agent';
                $pid = $agentAdminId;
            } elseif ($activeCount === 1) {
                $type = 'admin';
                $pid = (int) $activeAdmins[0]['id'];
            } else {
                $type = 'admin';
                $pid = 0; // unresolved
            }
            $pk = $hasParticipantKey ? (' participant_key=' . (string) ($row['participant_key'] ?? '')) : '';
            $lines[] = sprintf(
                'id=%d kb=%d agent_admin_id=%s%s -> type=%s participant_id=%s',
                (int) $row['id'],
                (int) $row['knowledge_base_id'],
                $agentAdminId === null ? 'NULL' : (string) $agentAdminId,
                $pk,
                $type,
                $pid === 0 ? 'UNRESOLVED' : (string) $pid,
            );
        }

        $report = implode("\n", $lines) . "\n";
        $io->writeln($report);

        $writeOpt = $input->getOption('write');
        if ($writeOpt !== null) {
            $path = $writeOpt === 'default'
                ? $this->aliases->get('@runtime') . '/backups/chat-participants-' . date('Ymd') . '/backfill-report.txt'
                : (string) $writeOpt;
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($path, $report);
            $io->success('Wrote ' . $path);
        }

        return $canAuto ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
