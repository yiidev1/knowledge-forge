<?php

declare(strict_types=1);

namespace App\AudioToText\Console;

use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\CallSessionFilename;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;

use function array_map;
use function array_unique;
use function count;
use function ksort;
use function sprintf;

/**
 * Links recordings uploaded by hand to the call they belong to.
 *
 * ## What it is for
 *
 * `audio_conversations.call_session_id` is written by the Order58 importer, which is told the id by the
 * provider. Every recording uploaded before the importer existed has NULL there, so nothing says which
 * of them are three channels of one call — and the derived Agent and Customer views, which need exactly
 * that, stay dormant for all of them.
 *
 * This recovers the id for the rows where the provider's own filename was kept. See
 * {@see CallSessionFilename} for why a filename is evidence rather than a guess, and for what it
 * refuses. Store, order id, duration and timestamps are never consulted.
 *
 * ## Why it is a command and not part of the migration
 *
 * Linking rows changes what screens show. A schema change runs during a deploy, when nobody is looking
 * at the result, and it would be the wrong moment to alter a page. This is opt-in, reports before it
 * writes, and can be read in full before `--write` is added.
 *
 * ## What it can and cannot do
 *
 * It writes exactly one column, and only where that column is still NULL — so it is idempotent, a second
 * run has nothing to do, and a value the importer wrote is never overwritten. It writes no status, no
 * transcript, no segment and no correction. Linking a call does **not** publish anything on its own:
 * a derived view still requires that call to have exactly one mixed recording whose roles a person
 * confirmed, and where it does not, every page reads exactly as it did before.
 *
 * Unlinking is one statement — see the class docblock of the migration — because the column is the only
 * thing this writes.
 */
#[AsCommand(
    name: 'kf:audio:link-call-sessions',
    description: 'Link hand-uploaded recordings to their call, from the provider filename they kept.',
)]
final class LinkCallSessionsCommand extends Command
{
    public function __construct(private readonly AudioConversationRepositoryInterface $conversations)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'write',
                null,
                InputOption::VALUE_NONE,
                'Record the links. Without this the command only reports, and writes nothing.',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'How many unlinked conversations to examine.',
                '1000',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $write = $input->getOption('write') === true;
        $limit = (int) $input->getOption('limit');

        $rows = $this->conversations->unlinkedForCallSessionBackfill($limit <= 0 ? 1000 : $limit);

        if ($rows === []) {
            $io->success('Every conversation already records which call it belongs to.');

            return ExitCode::OK;
        }

        /** @var array<int, list<Candidate>> $candidates */
        $candidates = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $sessionId = CallSessionFilename::sessionIdIn($row['filename']);

            // A name the provider did not produce. Left NULL rather than linked on a resemblance: a
            // wrong link would put one call's words on another call's page.
            if ($sessionId === null || $row['storeSourceId'] === null) {
                $skipped++;

                continue;
            }

            $candidates[$row['id']][] = new Candidate(
                $row['id'],
                $row['storeSourceId'],
                $row['recordingType'],
                (string) $row['filename'],
                $sessionId,
            );
        }

        /** @var list<Candidate> $planned */
        $planned = [];

        foreach ($candidates as $group) {
            $sessions = array_unique(array_map(
                static fn(Candidate $c): string => $c->callSessionId,
                $group,
            ));

            // One upload, two recordings, two different calls' filenames — a legacy Customer + Agent
            // pair assembled by hand from files that do not belong to the same call. There is no answer
            // to "which call is this upload of", so it is left unlinked rather than given whichever
            // half was inserted first.
            if (count($sessions) !== 1) {
                $skipped += count($group);

                continue;
            }

            $planned[] = $group[0];
        }

        $io->section(sprintf(
            '%d unlinked conversation(s) examined; %d would be linked, %d left alone',
            count($rows),
            count($planned),
            $skipped,
        ));

        if ($planned === []) {
            $io->warning('None of them carries a filename this command recognises. Nothing to do.');

            return ExitCode::OK;
        }

        // Grouped, because the unit a reader cares about is the call rather than the row: three lines
        // under one id is the shape that says "these three belong together", which is the whole point.
        $byCall = [];

        foreach ($planned as $row) {
            $byCall[$row->storeSourceId . ' / ' . $row->callSessionId][] = $row;
        }

        ksort($byCall);

        $io->table(
            ['store / call session', 'conversation', 'recording', 'file'],
            $this->rowsFor($byCall),
        );

        if (!$write) {
            $io->note('Nothing was written. Re-run with --write to record these links.');

            return ExitCode::OK;
        }

        $written = 0;
        $raced = 0;

        foreach ($planned as $row) {
            if ($this->conversations->recordCallSession($row->conversationId, $row->callSessionId)) {
                $written++;

                continue;
            }

            // The row gained a session id between the read and the write, which only the importer does
            // — and the provider's own value is the better one. Counted, not overwritten.
            $raced++;
        }

        $io->success(sprintf('%d conversation(s) linked.', $written));

        if ($raced > 0) {
            $io->note(sprintf('%d were linked by the importer meanwhile and were left as they were.', $raced));
        }

        return ExitCode::OK;
    }

    /**
     * @param array<string, list<Candidate>> $byCall
     *
     * @return list<list<string>>
     */
    private function rowsFor(array $byCall): array
    {
        $table = [];

        foreach ($byCall as $key => $group) {
            $first = true;

            foreach ($group as $row) {
                $table[] = [
                    $first ? $key : '',
                    (string) $row->conversationId,
                    $row->recordingType ?? '—',
                    $row->filename,
                ];
                $first = false;
            }
        }

        return $table;
    }
}

/**
 * One conversation that could be linked, and to which call.
 *
 * A shaped object rather than an array because the grouping below has to compare and then carry these,
 * and an array would be an `array-key => mixed` by the time it reached the write.
 */
final readonly class Candidate
{
    public function __construct(
        public int $conversationId,
        public int $storeSourceId,
        public ?string $recordingType,
        public string $filename,
        public string $callSessionId,
    ) {}
}
