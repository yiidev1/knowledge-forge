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
use function is_dir;
use function mkdir;
use function sprintf;

/**
 * Pre-flight report for canonical chat-thread migration: duplicate groups, messages to move,
 * and old conversation id → canonical id mapping. Does not modify data.
 */
#[AsCommand(
    name: 'chat:thread-merge-report',
    description: 'Reports duplicate conversation groups and the old→canonical merge map (read-only).',
)]
final class ThreadMergeReportCommand extends Command
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
            'Write the report to this path (default: runtime/backups/chat-threads-YYYYMMDD/merge-map.txt)',
            'default',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $zero = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%conversations}} WHERE [[agent_admin_id]] = 0',
        )->queryScalar();
        $negative = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%conversations}} WHERE [[agent_admin_id]] < 0',
        )->queryScalar();
        $conversations = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%conversations}}',
        )->queryScalar();
        $messages = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM {{%messages}}',
        )->queryScalar();

        $groups = $this->db->createCommand(
            'SELECT [[knowledge_base_id]], COALESCE([[agent_admin_id]], 0) AS [[participant_key]], '
            . 'COUNT(*) AS [[conversation_count]], MIN([[id]]) AS [[canonical_id]], '
            . 'GROUP_CONCAT([[id]] ORDER BY [[id]]) AS [[all_ids]] '
            . 'FROM {{%conversations}} '
            . 'GROUP BY [[knowledge_base_id]], COALESCE([[agent_admin_id]], 0) '
            . 'HAVING COUNT(*) > 1 '
            . 'ORDER BY [[conversation_count]] DESC, [[knowledge_base_id]]',
        )->queryAll();

        $toMerge = 0;
        $messagesToMove = 0;
        $mapLines = [];
        foreach ($groups as $group) {
            $canonical = (int) $group['canonical_id'];
            $allIds = array_map(intval(...), explode(',', (string) $group['all_ids']));
            $dupes = array_values(array_filter($allIds, static fn(int $id): bool => $id !== $canonical));
            $toMerge += count($dupes);
            foreach ($dupes as $oldId) {
                $msgCount = (int) $this->db->createCommand(
                    'SELECT COUNT(*) FROM {{%messages}} WHERE [[conversation_id]] = :id',
                    [':id' => $oldId],
                )->queryScalar();
                $messagesToMove += $msgCount;
                $mapLines[] = sprintf(
                    'old=%d -> canonical=%d (kb=%d participant=%d messages=%d)',
                    $oldId,
                    $canonical,
                    (int) $group['knowledge_base_id'],
                    (int) $group['participant_key'],
                    $msgCount,
                );
            }
        }

        $lines = [
            '=== chat:thread-merge-report ===',
            'Generated: ' . date('c'),
            '',
            'VALIDATION',
            'agent_admin_id = 0: ' . $zero,
            'agent_admin_id < 0: ' . $negative,
            'participant_key 0 is reserved for admin (COALESCE(agent_admin_id, 0))',
            '',
            'COUNTS',
            'conversations_total: ' . $conversations,
            'messages_total: ' . $messages,
            'duplicate_groups: ' . count($groups),
            'conversations_to_merge: ' . $toMerge,
            'messages_to_move: ' . $messagesToMove,
            '',
            'OLD -> CANONICAL MAP',
        ];
        if ($mapLines === []) {
            $lines[] = '(no duplicate groups)';
        } else {
            foreach ($mapLines as $line) {
                $lines[] = $line;
            }
        }

        $report = implode("\n", $lines) . "\n";
        $io->writeln($report);

        if ($zero > 0 || $negative > 0) {
            $io->error('Invalid agent_admin_id values found. Fix before migration.');

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $writeOpt = $input->getOption('write');
        if ($writeOpt !== null) {
            $path = $writeOpt === 'default'
                ? $this->aliases->get('@runtime') . '/backups/chat-threads-' . date('Ymd') . '/merge-map.txt'
                : (string) $writeOpt;
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($path, $report);
            $io->success('Wrote ' . $path);
        }

        return ExitCode::OK;
    }
}
