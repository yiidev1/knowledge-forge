<?php

declare(strict_types=1);

namespace App\AudioToText\Console;

use App\AudioToText\Application\Speaker\SpeakerRoleMapper;
use App\AudioToText\Domain\Speaker\SeparationBalance;
use App\AudioToText\Domain\Speaker\SeparationReviewReason;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yiisoft\Yii\Console\ExitCode;

use function count;
use function is_array;
use function json_decode;
use function ksort;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Says why each existing NEEDS_REVIEW recording was left for a person, and can record the answer.
 *
 * ## Why a command rather than a back-fill in the migration
 *
 * The diagnosis is computed during transcription and, until now, discarded. Recovering it for rows
 * written earlier means re-running part of the pipeline, and **only part of it is recoverable**: the
 * diarizer's raw segments are not retained, so the two gates that run before role mapping —
 * attribution share and speaker balance — cannot be re-measured from what is stored. What survives is
 * `speaker_segments`, which is the aligned output, and that is enough to re-run the balance check and
 * the role mapper on it.
 *
 * A migration that quietly wrote a value for every row would therefore be writing a figure that looks
 * measured and is not. This command writes only what it can actually derive, leaves the rest NULL, and
 * says which is which — so a count of reasons afterwards is a count of findings, not of assumptions.
 *
 * ## It cannot change a decision
 *
 * Read-only by default; `--write` touches exactly one column, `speaker_review_reason`. It never writes
 * a status, a confidence, a role, a transcript, a segment or a correction, and it cannot move a
 * recording between COMPLETED and NEEDS_REVIEW. Running it on a live database changes nothing an
 * administrator or the worker would do differently — which is what makes it safe to run at all.
 */
#[AsCommand(
    name: 'kf:audio:diagnose-speaker-review',
    description: 'Explain why completed recordings were left for speaker-role review.',
)]
final class SpeakerReviewDiagnosisCommand extends Command
{
    public function __construct(
        private readonly TranscriptionJobRepositoryInterface $jobs,
        private readonly SpeakerRoleMapper $roleMapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'write',
                null,
                InputOption::VALUE_NONE,
                'Record the diagnosis. Without this the command only reports, and writes nothing.',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'How many recordings to examine.',
                '500',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $write = $input->getOption('write') === true;
        $limit = (int) $input->getOption('limit');

        $jobs = $this->jobs->needingSpeakerReviewDiagnosis($limit <= 0 ? 500 : $limit);

        if ($jobs === []) {
            $io->success('Every recording that needed a diagnosis already has one.');

            return ExitCode::OK;
        }

        $counts = [];
        $undecidable = 0;
        $written = 0;

        foreach ($jobs as $job) {
            $reason = $this->diagnose($job->speakerSegmentsJson);

            if ($reason === null) {
                // The gates that ran before role mapping cannot be re-measured from stored data, so
                // this row keeps its NULL rather than gaining a guess.
                $undecidable++;

                continue;
            }

            $counts[$reason->value] = ($counts[$reason->value] ?? 0) + 1;

            if ($write) {
                $this->jobs->recordSpeakerReviewDiagnosis($job->id, $reason);
                $written++;
            }
        }

        ksort($counts);

        $io->section(sprintf('%d recording(s) examined', count($jobs)));

        foreach ($counts as $value => $n) {
            $io->writeln(sprintf('  %-26s %d', $value, $n));
        }

        if ($undecidable > 0) {
            $io->writeln(sprintf(
                '  %-26s %d  (left NULL: the diarizer output they failed on is not retained)',
                '(not derivable)',
                $undecidable,
            ));
        }

        $io->newLine();
        $io->writeln($write
            ? sprintf('Recorded %d diagnosis/diagnoses. No other column was touched.', $written)
            : 'Nothing was written. Pass --write to record these.');

        return ExitCode::OK;
    }

    /**
     * Re-run the derivable half of the pipeline on stored segments.
     *
     * The order matches {@see \App\AudioToText\Application\Speaker\SpeakerSeparationService} so a
     * recomputed answer means what a freshly computed one would. Null where the recording failed a gate
     * this cannot see.
     */
    private function diagnose(?string $segmentsJson): ?SeparationReviewReason
    {
        if ($segmentsJson === null) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($segmentsJson, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || $decoded === []) {
            return null;
        }

        $utterances = [];

        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $utterances[] = new SpeakerUtterance(
                (int) ($row['start_ms'] ?? 0),
                (int) ($row['end_ms'] ?? 0),
                (string) ($row['speaker'] ?? SpeakerRole::UNKNOWN->value),
                SpeakerRole::UNKNOWN,
                (string) ($row['text'] ?? ''),
                (float) ($row['confidence'] ?? 0.0),
            );
        }

        if ($utterances === []) {
            return null;
        }

        if (!SeparationBalance::of($utterances)->isUsable()) {
            return SeparationReviewReason::UNUSABLE_SPEAKER_BALANCE;
        }

        $mapping = $this->roleMapper->map($utterances);

        if ($mapping['reasonCode'] !== null) {
            return $mapping['reasonCode'];
        }

        // A mapping was produced. Whether it cleared the threshold is not re-decided here — the row is
        // NEEDS_REVIEW, so by definition it did not, and the recorded confidence is the original run's.
        return SeparationReviewReason::ROLE_CONFIDENCE_LOW;
    }
}
