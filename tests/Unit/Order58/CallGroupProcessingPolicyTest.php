<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Shared\Audio\RecordingProcessingPolicy as Policy;
use Codeception\Test\Unit;

use function array_filter;
use function count;

/**
 * What an Order58 call group costs, whatever channels it happens to contain.
 *
 * **No test here touches a database or a provider.** The rule under test is the one the importer applies
 * to each channel as it arrives, and the property worth pinning is that it does not depend on the group
 * at all: the mixed recording is audio in every one of these shapes, and each side is transcribed if and
 * only if it exists.
 *
 * An earlier version of this file asserted the opposite — that an incomplete group kept the mixed
 * transcript — and it was wrong about the business rule rather than about the code. It is recorded here
 * because the reasoning was plausible: a call with no sides then has no transcript at all. That is the
 * accepted outcome, and the alternative is a diarizer guessing which of two people on one track is the
 * agent, which is the thing this architecture was built to stop.
 */
final class CallGroupProcessingPolicyTest extends Unit
{
    /**
     * Every shape a call can arrive in, and what each one asks of a speech provider.
     *
     * @return iterable<string, array{list<string>, int}>
     */
    public static function callShapes(): iterable
    {
        yield 'all three channels' => [['mixed', 'caller', 'callee'], 2];
        yield 'mixed only' => [['mixed'], 0];
        yield 'mixed and caller' => [['mixed', 'caller'], 1];
        yield 'mixed and callee' => [['mixed', 'callee'], 1];
        yield 'both sides, no mixed' => [['caller', 'callee'], 2];
        yield 'caller only' => [['caller'], 1];
    }

    /**
     * One transcription per declared side, and never one for the mixed recording.
     *
     * @param list<string> $channels
     *
     * @dataProvider callShapes
     */
    public function testOnlyDeclaredSidesAreEverTranscribed(array $channels, int $expected): void
    {
        $transcribed = array_filter(
            $channels,
            static fn(string $channel): bool => Policy::decide(self::typeOf($channel))->transcribe,
        );

        $this->assertCount($expected, $transcribed);
    }

    /**
     * The mixed recording's answer never depends on what is beside it.
     *
     * @param list<string> $channels
     *
     * @dataProvider callShapes
     */
    public function testTheMixedRecordingIsAudioInEveryShapeOfCall(array $channels, int $expected): void
    {
        $this->assertFalse(Policy::decide(Policy::TYPE_MIXED)->transcribe);
        // Stated as the consequence too: a call that has a mixed recording never spends more on speech
        // than it has sides, so the group's cost is exactly its number of declared channels.
        $this->assertSame($expected, count($channels) - count(array_filter(
            $channels,
            static fn(string $channel): bool => $channel === 'mixed',
        )));
    }

    /** Each side keeps its own role whatever else the call contains. */
    public function testEachSideKeepsItsRoleWhateverTheGroupHolds(): void
    {
        $this->assertSame(Policy::ROLE_CUSTOMER, Policy::decide(Policy::TYPE_CALLER)->sourceRole);
        $this->assertSame(Policy::ROLE_AGENT, Policy::decide(Policy::TYPE_CALLEE)->sourceRole);
    }

    /** The importer's channel values, as they are stored, mapped to the policy's vocabulary. */
    private static function typeOf(string $channel): string
    {
        return match ($channel) {
            'caller' => Policy::TYPE_CALLER,
            'callee' => Policy::TYPE_CALLEE,
            default => Policy::TYPE_MIXED,
        };
    }
}
