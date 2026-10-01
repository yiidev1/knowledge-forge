<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\ConversationStatus;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\ProcessingStage;
use App\AudioToText\Domain\Tts\AiAudioState;
use App\AudioToText\Domain\Tts\TtsEnqueueOutcome;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsStatus;
use App\AudioToText\Domain\WorkerMode;
use App\AudioToText\Domain\WorkerProcessState;
use App\AudioToText\Domain\WorkerSchedulerState;
use App\AudioToText\Domain\WorkerStatusView;
use App\Order58\Domain\Order58ImportStatus;
use App\Shared\Audio\RecordingAcquisitionOutcome;
use App\Shared\Audio\RecordingAcquisitionState;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function glob;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function str_contains;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * The words this product uses to the person looking at it.
 *
 * Internally there are queues, workers, jobs and claims, and those are the right words for them. None of
 * them is the right word for an administrator who uploaded a recording: a queue is not a thing they can
 * see, join or leave, a worker is not a thing they asked for, and "job" names this application's
 * bookkeeping rather than their audio. Every one of them was on screen, and each said something true
 * about the machine and nothing useful about the recording.
 *
 * What replaced them says what is true of the recording instead — the same five words wherever one is
 * reported, so a reader who learns them on one screen has learned them on all of them.
 *
 * ## Why this is a test and not a convention
 *
 * A convention holds until the next feature. Status labels are written once and read on four screens,
 * and the next one to be added will be named by whoever adds it — which is exactly when a queue surfaces
 * again. This fails on the day that happens rather than on the day somebody notices.
 */
final class ClientVocabularyTest extends TestCase
{
    /**
     * Phrases that can only ever be user-facing text.
     *
     * Multi-word on purpose. A bare "job" or "queue" appears legitimately in `$job`, `queuePositionOf()`
     * and a hundred comments, so banning the word across source would be unenforceable and would be
     * silenced rather than obeyed. These cannot be anything but prose.
     *
     * @var non-empty-list<string>
     */
    private const BANNED_PHRASES = [
        'queue position',
        'added to queue',
        'worker started',
        'job created',
        'job processing',
        'jobs added',
        'transcription worker',
        'processing worker',
        'queue text',
    ];

    /**
     * Every label map a screen reads a status out of.
     *
     * These are the strings that reach a badge, so **no** banned word may appear in any of them, not
     * even as part of a longer phrase.
     *
     * @return list<array{string, string}> [where it came from, the label]
     */
    private static function labels(): array
    {
        $labels = [];

        foreach (JobStatus::cases() as $case) {
            $labels[] = ['JobStatus::' . $case->name, $case->label()];
        }

        foreach (ConversationStatus::cases() as $case) {
            $labels[] = ['ConversationStatus::' . $case->name, $case->label()];
        }

        foreach (AiAudioState::cases() as $case) {
            $labels[] = ['AiAudioState::' . $case->name, $case->label()];
        }

        foreach (TtsStatus::cases() as $case) {
            $labels[] = ['TtsStatus::' . $case->name, $case->label()];
        }

        foreach (Order58ImportStatus::cases() as $case) {
            $labels[] = ['Order58ImportStatus::' . $case->name, $case->label()];
        }

        // The recordings pages' own vocabulary, both levels of it. In this file rather than its own
        // because the rule is one rule: a reader moving between these pages and the store's audio page
        // meets one set of words, not three.
        foreach (RecordingAcquisitionState::cases() as $case) {
            $labels[] = ['RecordingAcquisitionState::' . $case->name, $case->label()];
        }

        foreach (RecordingAcquisitionOutcome::cases() as $case) {
            $labels[] = ['RecordingAcquisitionOutcome::' . $case->name, $case->label()];
        }

        foreach (ProcessingStage::cases() as $case) {
            $labels[] = ['ProcessingStage::' . $case->name, $case->label()];
        }

        // Not a badge but a whole sentence shown in a notice, which is if anything a worse place to
        // name the mechanism: a badge is a word a reader skims, a notice is addressed to them.
        foreach (TtsEnqueueOutcome::cases() as $case) {
            foreach (TtsOutputType::cases() as $type) {
                $labels[] = ['TtsEnqueueOutcome::' . $case->name . '(' . $type->name . ')', $case->message($type)];
            }
        }

        // The densest surface there was, and the one most likely to regrow the habit: every sentence
        // here describes the mechanism, so each is one careless edit away from naming it again. The
        // whole cross-product is cheap, so take all of it rather than a sample.
        foreach (WorkerMode::cases() as $mode) {
            foreach (WorkerProcessState::cases() as $process) {
                foreach (WorkerSchedulerState::cases() as $scheduler) {
                    foreach ([true, false] as $everRan) {
                        $view = new WorkerStatusView($mode, $process, $scheduler, 34, $everRan);
                        $where = sprintf(
                            'WorkerStatusView(%s, %s, %s, everRan: %s)',
                            $mode->name,
                            $process->name,
                            $scheduler->name,
                            $everRan ? 'yes' : 'no',
                        );

                        $labels[] = [$where . '->label()', $view->label()];

                        $detail = $view->detail();

                        if ($detail !== null) {
                            $labels[] = [$where . '->detail()', $detail];
                        }
                    }
                }
            }
        }

        $labels[] = ['WorkerStatusView::neverRan()->label()', WorkerStatusView::neverRan()->label()];
        $labels[] = ['WorkerStatusView::neverRan()->detail()', (string) WorkerStatusView::neverRan()->detail()];

        return $labels;
    }

    /** No status a reader sees names the mechanism that produces it. */
    public function testNoStatusLabelNamesAQueueOrAWorker(): void
    {
        foreach (self::labels() as [$where, $label]) {
            foreach (['queue', 'worker', 'job'] as $banned) {
                self::assertStringNotContainsStringIgnoringCase(
                    $banned,
                    $label,
                    $where . ' reads "' . $label . '", which names the machine rather than the recording.',
                );
            }
        }
    }

    /**
     * And no label is an internal value that escaped.
     *
     * `NOT_REQUESTED` on a badge would be worse than the word it replaced: it is not even English.
     */
    public function testNoStatusLabelIsARawEnumValue(): void
    {
        foreach (self::labels() as [$where, $label]) {
            self::assertSame(
                0,
                preg_match('/^[A-Z_]+$/', $label),
                $where . ' reads "' . $label . '", which is a stored value rather than a sentence.',
            );
        }
    }

    /** The five words a transcription is reported in, and they are the same everywhere. */
    public function testTheTranscriptionLifecycleReadsInTheProductsOwnWords(): void
    {
        self::assertSame('Ready for transcription', JobStatus::NOT_REQUESTED->label());
        self::assertSame('Transcription requested', JobStatus::QUEUED->label());
        self::assertSame('Transcribing', JobStatus::PROCESSING->label());
        self::assertSame('Completed', JobStatus::COMPLETED->label());
        self::assertSame('Failed', JobStatus::FAILED->label());

        // The aggregate of a whole upload uses the same words, so a row and the recording inside it
        // cannot describe the same moment differently.
        foreach ([JobStatus::NOT_REQUESTED, JobStatus::QUEUED, JobStatus::PROCESSING] as $status) {
            self::assertSame(
                $status->label(),
                ConversationStatus::from($status->value)->label(),
                'One upload and one recording must read alike.',
            );
        }
    }

    /** No template on a screen a client sees carries one of the banned phrases. */
    public function testNoClientFacingTemplateNamesTheMachinery(): void
    {
        foreach (self::templates() as $path => $source) {
            $text = strtolower($source);

            foreach (self::BANNED_PHRASES as $phrase) {
                self::assertFalse(
                    str_contains($text, $phrase),
                    $path . ' says "' . $phrase . '" to somebody who did not ask about one.',
                );
            }
        }
    }

    /**
     * The status panel, checked word by word rather than phrase by phrase.
     *
     * Its count labels were single words — "Queued", "Processing" — so the phrase list above could never
     * have caught them. This reads only the text between tags, which is exactly what a reader sees and
     * nothing of the class names around it.
     */
    public function testTheStatusPanelShowsNoBannedWordAnywhereOnScreen(): void
    {
        $path = dirname(__DIR__, 3) . '/src/AudioToText/Web/_partial/worker-status.php';
        $source = (string) file_get_contents($path);

        // Drop the docblock first: it discusses the worker on purpose, and should.
        $markup = (string) preg_replace('/\/\*.*?\*\//s', '', $source);

        preg_match_all('/>([^<>{}?]+)</', $markup, $matches);

        $seen = 0;

        foreach ($matches[1] as $text) {
            $text = trim($text);

            if ($text === '') {
                continue;
            }

            ++$seen;

            foreach (['queue', 'worker', 'job'] as $banned) {
                self::assertStringNotContainsStringIgnoringCase(
                    $banned,
                    $text,
                    'The status panel shows "' . $text . '", which names the machine rather than the audio.',
                );
            }
        }

        self::assertGreaterThan(3, $seen, 'No on-screen text was extracted, so this proves nothing.');
    }

    /** And the panel says the four things it is for, in the product's words. */
    public function testTheStatusPanelStillReportsTheFourThingsItIsFor(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/AudioToText/Web/_partial/worker-status.php',
        );

        foreach (['>Waiting<', '>In progress<', 'Completed (', 'Failed ('] as $expected) {
            self::assertStringContainsString(
                $expected,
                $source,
                'Rewording the panel must not cost it a count.',
            );
        }
    }

    /**
     * The polled badge and the rendered badge must read alike.
     *
     * `admin.js` replaces the server-rendered text on every poll, so each of these maps has a PHP twin
     * that renders the same state before the first poll lands. Drift shows up as one recording changing
     * its wording without changing its state — and it is how the old vocabulary comes back, because a
     * duplicated map is the one surface that looks finished after you have fixed half of it.
     *
     * That is not hypothetical: `ProcessingStage::QUEUED` still read "Waiting for the worker" after
     * `STAGE_LABELS` had been corrected, and only the rendered page gave it away.
     *
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function labelMaps(): iterable
    {
        $statuses = [];

        foreach (JobStatus::cases() as $case) {
            $statuses[] = [$case->value, $case->label()];
        }

        $stages = [];

        foreach (ProcessingStage::cases() as $case) {
            $stages[] = [$case->value, $case->label()];
        }

        yield 'STATUS_LABELS' => ['STATUS_LABELS', $statuses];
        yield 'STAGE_LABELS' => ['STAGE_LABELS', $stages];
    }

    /**
     * @dataProvider labelMaps
     *
     * @param list<array{string, string}> $expected
     */
    public function testThePolledLabelsMirrorTheServersOwn(string $name, array $expected): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/assets/main/admin.js');

        self::assertSame(
            1,
            preg_match('/var ' . $name . ' = \{(.*?)\};/s', $source, $block),
            $name . ' could not be found; this test is checking nothing.',
        );

        preg_match_all("/([A-Z_]+):\s*'([^']*)'/", $block[1], $pairs, PREG_SET_ORDER);

        $mapped = [];

        foreach ($pairs as [, $key, $label]) {
            $mapped[$key] = $label;
        }

        foreach ($expected as [$value, $label]) {
            self::assertArrayHasKey(
                $value,
                $mapped,
                $value . ' is missing from ' . $name . ', so the page would show a dash for it.',
            );
            self::assertSame(
                $label,
                $mapped[$value],
                $name . "['" . $value . "'] disagrees with what the server renders.",
            );
        }
    }

    /** Nor does the JavaScript that writes text into those screens. */
    public function testNoClientFacingScriptNamesTheMachinery(): void
    {
        foreach ([
            'main/admin.js',
            'audio-store/audio-store.js',
            'order58-calls/order58-calls.js',
        ] as $relative) {
            $path = dirname(__DIR__, 3) . '/assets/' . $relative;
            $source = @file_get_contents($path);

            if ($source === false) {
                continue;
            }

            // Comments in these files discuss the queue and the worker at length, and should: that is
            // where the reasoning lives. Only what is assigned to text may not.
            preg_match_all('/(?:textContent|innerText)\s*=\s*([^;]+);/', $source, $matches);

            foreach ($matches[1] as $assigned) {
                foreach (self::BANNED_PHRASES as $phrase) {
                    self::assertFalse(
                        str_contains(strtolower($assigned), $phrase),
                        $relative . ' writes "' . $phrase . '" onto the page.',
                    );
                }
            }

            // A label map is assigned through a variable, so the check above cannot see into it. Every
            // string in one reaches a badge, so these are checked word by word like the PHP label maps.
            preg_match_all('/var [A-Z_]*LABELS[A-Z_]* = \{(.*?)\};/s', $source, $blocks);

            foreach ($blocks[1] as $block) {
                preg_match_all("/'([^']*)'/", $block, $strings);

                foreach ($strings[1] as $label) {
                    foreach (['queue', 'worker', 'job'] as $banned) {
                        self::assertStringNotContainsStringIgnoringCase(
                            $banned,
                            $label,
                            $relative . ' maps a status to "' . $label . '", which names the machine.',
                        );
                    }
                }
            }
        }
    }

    /**
     * @return array<string, string> relative path => source
     */
    private static function templates(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        foreach ([
            '/src/AudioToText/Web/**/*.php',
            '/src/AudioToText/Web/*.php',
            '/src/Order58/Web/Calls/*.php',
            '/src/Order58/Web/CallRecordings/*.php',
        ] as $pattern) {
            foreach (glob($root . $pattern, GLOB_BRACE) ?: [] as $path) {
                if (str_contains($path, 'template.php') || str_contains($path, '_partial')) {
                    $found[substr($path, strlen($root) + 1)] = (string) file_get_contents($path);
                }
            }
        }

        self::assertNotSame([], $found, 'No templates were found to check, so this proves nothing.');

        return $found;
    }
}
