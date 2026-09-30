<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Console\LinkCallSessionsCommand;
use App\AudioToText\Domain\CallSessionFilename;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Domain\Speaker\DerivedConversation;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\Speaker\TranscriptVoice;
use App\AudioToText\Domain\SpeakerRole;
use App\Tests\Support\Fake\AudioToText\LinkedConversations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use ReflectionMethod;

use function array_map;

/**
 * How recordings get linked to a call, and how a borrowed side of one is presented.
 *
 * Two small things with one thing in common: both are places where being approximately right would be
 * worse than doing nothing. A filename that nearly matches would link one call's recordings to another,
 * and a borrowed turn drawn with the usual controls would post an edit against the wrong job.
 */
final class CallSessionLinkingTest extends TestCase
{
    /** The provider's own three shapes, which is what an operator who kept the name has. */
    public function testItReadsTheSessionIdOutOfTheProvidersOwnFilenames(): void
    {
        self::assertSame('22449119', CallSessionFilename::sessionIdIn('22449119.wav'));
        self::assertSame('22449119', CallSessionFilename::sessionIdIn('22449119-caller.wav'));
        self::assertSame('22449119', CallSessionFilename::sessionIdIn('22449119-callee.wav'));
        self::assertSame('22449119', CallSessionFilename::sessionIdIn('22449119-CALLEE.WAV'));
    }

    /**
     * 4 (in part) / the backfill's refusals: anything else is left unlinked.
     *
     * A resemblance is not evidence. `22449119 (1).wav` is the shape a second download has, and linking
     * it on the digits inside would be exactly the guess this command exists not to make.
     */
    public function testItRefusesEverythingThatIsNotThatConvention(): void
    {
        foreach ([
            null,
            '',
            'kongs-kitchen-monday.wav',
            '22449119 (1).wav',
            'call-22449119.wav',
            '22449119-mixed.wav',
            '22449119.mp3',
            '1234.wav',
            '22449119-caller.wav.bak',
        ] as $filename) {
            self::assertNull(
                CallSessionFilename::sessionIdIn($filename),
                'Refused: ' . ($filename ?? 'null'),
            );
        }
    }

    /**
     * The rule takes a filename and nothing else, so there is nothing else it could consult.
     *
     * Asserted on the signature rather than by reading the file for forbidden words — the class
     * docblock names store, order and duration precisely to say they are *not* evidence, and a test
     * that searched the text would fail on the explanation.
     */
    public function testTheRuleIsGivenNothingButAFilename(): void
    {
        $method = new ReflectionMethod(CallSessionFilename::class, 'sessionIdIn');

        self::assertCount(1, $method->getParameters());
        self::assertSame('filename', $method->getParameters()[0]->getName());
        self::assertTrue($method->isStatic(), 'Pure: it holds no repository to ask a second question of.');
    }

    /**
     * A borrowed side is drawn with the role's own label, and every turn reads as settled.
     *
     * The label is the role rather than the recording type on purpose: these words were attributed to
     * this person on the conversation where the two speakers were actually separated, and confirmed
     * there. "Callee" would be the weaker fact, and it is the one the reader already has from the tab.
     */
    public function testABorrowedSideIsLabelledByTheConfirmedRole(): void
    {
        $voice = TranscriptVoice::forRecording(RecordingType::Callee);
        self::assertNotNull($voice);

        $derived = new DerivedConversation(
            [
                $this->utterance('Hi.aaaa', SpeakerRole::AGENT, 1520, 2160),
                $this->utterance('Anything else?', SpeakerRole::AGENT, 8640, 15280),
            ],
            SpeakerRole::AGENT,
            'a0652255c038ba123ae6e3d177edbbe9',
        );

        $view = ConversationView::derived($derived->utterances, $derived->role, $voice);

        self::assertTrue($view->rolesPublished);
        self::assertSame(['Agent', 'Agent'], array_map(
            static fn(object $t): string => (string) $t->label,
            $view->turns,
        ));
        self::assertSame(['Hi.aaaa', 'Anything else?'], array_map(
            static fn(object $t): string => (string) $t->text,
            $view->turns,
        ));

        foreach ($view->turns as $turn) {
            self::assertTrue($turn->confirmed);
        }

        // The mapper's guess about which cluster is the agent describes a question this recording does
        // not pose, and the confidence was measured on a different file.
        self::assertSame([], $view->hypotheses);
        self::assertNull($view->confidence);
    }

    /**
     * An upload whose two recordings name different calls is left unlinked.
     *
     * A legacy Customer + Agent pair is one conversation with two jobs, and one in this database was
     * assembled by hand from two different calls' files — `21896109.wav` beside `21884059.wav`. There is
     * no answer to which call that upload is of, so it gets none; linking it to whichever half the query
     * returned first would be recording a coincidence of insertion order as a fact.
     *
     * Found by running the backfill: it linked that row before this rule existed.
     */
    public function testAnUploadWhoseRecordingsNameDifferentCallsIsLeftUnlinked(): void
    {
        $conversations = (new LinkedConversations())
            ->unlinked(169, '21896109.wav')
            ->unlinked(169, '21884059.wav')
            ->unlinked(6443, '22449119.wav')
            ->unlinked(6445, '22449119-callee.wav');

        $output = $this->runBackfill($conversations, write: true);

        self::assertSame(
            [[6443, '22449119'], [6445, '22449119']],
            $conversations->written,
            'The disagreeing upload is never written; the two that agree are.',
        );
        self::assertStringContainsString('2 would be linked, 2 left alone', $output);
    }

    /** Without --write the command reports and touches nothing. */
    public function testTheBackfillWritesNothingWithoutTheFlag(): void
    {
        $conversations = (new LinkedConversations())->unlinked(6443, '22449119.wav');

        $output = $this->runBackfill($conversations, write: false);

        self::assertSame([], $conversations->written);
        self::assertStringContainsString('Re-run with --write', $output);
    }

    private function runBackfill(LinkedConversations $conversations, bool $write): string
    {
        $tester = new CommandTester(new LinkCallSessionsCommand($conversations));
        $tester->execute($write ? ['--write' => true] : []);

        return $tester->getDisplay();
    }

    private function utterance(string $text, SpeakerRole $role, int $from, int $to): SpeakerUtterance
    {
        return new SpeakerUtterance($from, $to, 'SPEAKER_01', $role, $text, 0.9, false, false);
    }
}
