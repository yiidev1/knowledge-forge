<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\Speaker\SeparationBalance;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\SpeakerRole;
use PHPUnit\Framework\TestCase;

/**
 * Whether the diarizer found two speakers — asked without reference to which is which.
 *
 * The case that motivated this: a 174-second order call where diarization gave one cluster 98.4% of the
 * speech and the other a single three-word turn, and role mapping then reported 1.000 confidence about
 * it. Both halves were internally consistent; the result was still unusable.
 *
 * The tests below deliberately include a heavily one-sided *but real* conversation, because rejecting
 * those would be the obvious wrong fix. Agents dominate order calls, and that is not a defect.
 */
final class SeparationBalanceTest extends TestCase
{
    /** A real call where the agent does most of the talking. Lopsided is not the same as broken. */
    public function testAOneSidedButGenuineConversationIsUsable(): void
    {
        $utterances = [];
        $at = 0;

        // Agent speaks in long turns, the customer answers briefly — 8 exchanges.
        for ($i = 0; $i < 8; ++$i) {
            $utterances[] = $this->utterance($at, $at + 9000, 'SPEAKER_00', 'Right, and how many orders of that would you like today');
            $at += 9000;
            $utterances[] = $this->utterance($at, $at + 1200, 'SPEAKER_01', 'Two please');
            $at += 1200;
        }

        $balance = SeparationBalance::of($utterances);

        // The quieter speaker holds well under a fifth of the conversation and is still a participant.
        self::assertLessThan(0.2, $balance->minorShare);
        self::assertTrue($balance->isUsable(), $balance->describe());
    }

    /** The reported failure: one cluster is effectively the whole call. */
    public function testAClusterOfAFewWordsIsNotASecondSpeaker(): void
    {
        $balance = SeparationBalance::of([
            $this->utterance(0, 37000, 'SPEAKER_00', str_repeat('a lot of agent speech here ', 30)),
            $this->utterance(37100, 39800, 'SPEAKER_01', 'order? A large'),
            $this->utterance(39900, 172700, 'SPEAKER_00', str_repeat('and still more agent speech ', 40)),
        ]);

        self::assertFalse($balance->isUsable());
        self::assertLessThan(0.05, $balance->minorShare);
        self::assertStringContainsString('quieter speaker', $balance->describe());
    }

    /** A monologue, or two voices the diarizer could not tell apart at all. */
    public function testASingleClusterIsNotUsable(): void
    {
        $balance = SeparationBalance::of([
            $this->utterance(0, 60000, 'SPEAKER_00', str_repeat('one voice only ', 40)),
        ]);

        self::assertFalse($balance->isUsable());
        self::assertSame('only one speaker was separated from the audio', $balance->describe());
    }

    /**
     * Three turns across three minutes is not a conversation that was separated, it is one that was
     * missed — even if the quieter cluster somehow cleared the speech floors.
     */
    public function testTooFewSpeakerChangesIsNotUsable(): void
    {
        $balance = SeparationBalance::of([
            $this->utterance(0, 80000, 'SPEAKER_00', str_repeat('a long opening stretch ', 30)),
            $this->utterance(80000, 95000, 'SPEAKER_01', str_repeat('a long single reply ', 10)),
        ]);

        self::assertSame(1, $balance->alternations);
        self::assertFalse($balance->isUsable());
        self::assertStringContainsString('speaker change', $balance->describe());
    }

    /** Plenty of back-and-forth is exactly what a usable separation looks like. */
    public function testManyAlternatingTurnsAreUsable(): void
    {
        $utterances = [];
        $at = 0;

        for ($i = 0; $i < 10; ++$i) {
            $speaker = $i % 2 === 0 ? 'SPEAKER_00' : 'SPEAKER_01';
            $utterances[] = $this->utterance($at, $at + 4000, $speaker, 'a reasonable amount of speech in this turn');
            $at += 4000;
        }

        $balance = SeparationBalance::of($utterances);

        self::assertSame(9, $balance->alternations);
        self::assertTrue($balance->isUsable(), $balance->describe());
    }

    /** Unattributed speech is neither speaker and must not prop one up. */
    public function testUnattributedSpeechIsNotCountedAsASpeaker(): void
    {
        $balance = SeparationBalance::of([
            $this->utterance(0, 40000, 'SPEAKER_00', str_repeat('agent speech ', 40)),
            $this->utterance(40000, 60000, SpeakerRole::UNKNOWN->value, str_repeat('unattributed ', 40)),
        ]);

        self::assertSame(1, $balance->speakerCount);
        self::assertFalse($balance->isUsable());
    }

    public function testNothingAtAllIsNotUsable(): void
    {
        self::assertFalse(SeparationBalance::of([])->isUsable());
    }

    /**
     * The measurement is about who spoke, not about what the mapper decided they were. Roles must make
     * no difference to it at all — that independence is what lets the two gates check different things.
     */
    public function testRolesDoNotAffectTheMeasurement(): void
    {
        $neutral = [];
        $labelled = [];
        $at = 0;

        for ($i = 0; $i < 10; ++$i) {
            $speaker = $i % 2 === 0 ? 'SPEAKER_00' : 'SPEAKER_01';
            $role = $i % 2 === 0 ? SpeakerRole::AGENT : SpeakerRole::CUSTOMER;
            $neutral[] = $this->utterance($at, $at + 4000, $speaker, 'some speech in this particular turn');
            $labelled[] = new SpeakerUtterance($at, $at + 4000, $speaker, $role, 'some speech in this particular turn', 1.0);
            $at += 4000;
        }

        $a = SeparationBalance::of($neutral);
        $b = SeparationBalance::of($labelled);

        self::assertSame($a->minorShare, $b->minorShare);
        self::assertSame($a->minorWords, $b->minorWords);
        self::assertSame($a->alternations, $b->alternations);
        self::assertSame($a->isUsable(), $b->isUsable());
    }

    private function utterance(int $start, int $end, string $speaker, string $text): SpeakerUtterance
    {
        return new SpeakerUtterance($start, $end, $speaker, SpeakerRole::UNKNOWN, $text, 1.0);
    }
}
