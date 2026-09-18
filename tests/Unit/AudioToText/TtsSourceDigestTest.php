<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Tts\TtsSourceDigest;
use App\AudioToText\Application\Tts\TtsTextChunker;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsUtterance;
use PHPUnit\Framework\TestCase;

use function count;
use function str_repeat;
use function strlen;

/**
 * The digest decides whether an administrator is told their audio is out of date.
 *
 * Both directions of wrongness cost something real. A false positive says "transcript changed" when it
 * has not, and clearing that notice means paying Deepgram again. A false negative leaves audio that says
 * "one ton" sitting under a transcript that says "wonton", with nothing on the page to suggest anything
 * is wrong.
 */
final class TtsSourceDigestTest extends TestCase
{
    // ------------------------------------------------------------------ it is stable

    public function testTheSameConversationAlwaysHashesTheSame(): void
    {
        $utterances = [
            new TtsUtterance(SpeakerRole::CUSTOMER, 'Two egg foo young please.'),
            new TtsUtterance(SpeakerRole::AGENT, 'Ready in 25 minutes.'),
        ];

        $this->assertSame(
            TtsSourceDigest::for(TtsOutputType::Mixed, $utterances),
            TtsSourceDigest::for(TtsOutputType::Mixed, $utterances),
        );
    }

    public function testItIsASha256(): void
    {
        $hash = TtsSourceDigest::for(TtsOutputType::Mixed, [new TtsUtterance(SpeakerRole::AGENT, 'Hello.')]);

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function testAnEmptyConversationStillHashes(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            TtsSourceDigest::for(TtsOutputType::Mixed, []),
        );
    }

    // ------------------------------------------------------------------ it notices real changes

    /** The case the whole feature exists for. */
    public function testCorrectingAWordChangesTheDigest(): void
    {
        $machine = [new TtsUtterance(SpeakerRole::CUSTOMER, 'One ton soup please.')];
        $corrected = [new TtsUtterance(SpeakerRole::CUSTOMER, 'Wonton soup please.')];

        $this->assertNotSame(
            TtsSourceDigest::for(TtsOutputType::Mixed, $machine),
            TtsSourceDigest::for(TtsOutputType::Mixed, $corrected),
        );
    }

    public function testReorderingTurnsChangesTheDigest(): void
    {
        $a = new TtsUtterance(SpeakerRole::CUSTOMER, 'Hello.');
        $b = new TtsUtterance(SpeakerRole::AGENT, 'Hello.');

        $this->assertNotSame(
            TtsSourceDigest::for(TtsOutputType::Mixed, [$a, $b]),
            TtsSourceDigest::for(TtsOutputType::Mixed, [$b, $a]),
        );
    }

    /** Moving a turn to the other speaker changes who says it, which changes the audio. */
    public function testChangingOnlyTheRoleChangesTheDigest(): void
    {
        $this->assertNotSame(
            TtsSourceDigest::for(TtsOutputType::Mixed, [new TtsUtterance(SpeakerRole::AGENT, 'Same words.')]),
            TtsSourceDigest::for(TtsOutputType::Mixed, [new TtsUtterance(SpeakerRole::CUSTOMER, 'Same words.')]),
        );
    }

    public function testTheOutputTypeIsPartOfTheDigest(): void
    {
        $utterances = [new TtsUtterance(SpeakerRole::AGENT, 'Ready in 25 minutes.')];

        $this->assertNotSame(
            TtsSourceDigest::for(TtsOutputType::Mixed, $utterances),
            TtsSourceDigest::for(TtsOutputType::Agent, $utterances),
        );
    }

    /** Whitespace is part of the text, so changing it is a change. */
    public function testWhitespaceIsSignificant(): void
    {
        $this->assertNotSame(
            TtsSourceDigest::for(TtsOutputType::Mixed, [new TtsUtterance(SpeakerRole::AGENT, 'a b')]),
            TtsSourceDigest::for(TtsOutputType::Mixed, [new TtsUtterance(SpeakerRole::AGENT, 'a  b')]),
        );
    }

    // ------------------------------------------------------------------ the framing is injective

    /**
     * The bug a newline-joined implementation would have.
     *
     * `implode("\n", ["AGENT: …"])` is not injective: one turn whose text happens to contain a newline
     * and a role prefix produces byte-for-byte the same string as two turns. Two genuinely different
     * conversations would then hash identically, and one of them would silently keep the other's audio.
     */
    public function testATurnContainingRoleLikeTextCannotImpersonateTwoTurns(): void
    {
        $oneTurn = [new TtsUtterance(SpeakerRole::AGENT, "Hello.\nCUSTOMER: Goodbye.")];
        $twoTurns = [
            new TtsUtterance(SpeakerRole::AGENT, 'Hello.'),
            new TtsUtterance(SpeakerRole::CUSTOMER, 'Goodbye.'),
        ];

        $this->assertNotSame(
            TtsSourceDigest::for(TtsOutputType::Mixed, $oneTurn),
            TtsSourceDigest::for(TtsOutputType::Mixed, $twoTurns),
        );
    }

    /** Quotes, backslashes and braces are content, and must not be able to shift the structure either. */
    public function testJsonSyntaxInsideATurnIsContentNotStructure(): void
    {
        $a = [new TtsUtterance(SpeakerRole::AGENT, '"],["CUSTOMER","')];
        $b = [
            new TtsUtterance(SpeakerRole::AGENT, ''),
            new TtsUtterance(SpeakerRole::CUSTOMER, ''),
        ];

        $this->assertNotSame(
            TtsSourceDigest::for(TtsOutputType::Mixed, $a),
            TtsSourceDigest::for(TtsOutputType::Mixed, $b),
        );
    }

    // ------------------------------------------------------------------ what it must ignore

    /**
     * Chunking happens after hashing and preserves its input exactly, so it can never move the digest.
     *
     * Asserted directly rather than argued: if it ever did, every generated file would go stale the day
     * somebody changed `DEEPGRAM_TTS_MAX_CHARS`.
     */
    public function testChunkingCannotChangeTheDigest(): void
    {
        $text = str_repeat('Egg foo young, no MSG, ready in 25 minutes. ', 200);
        $utterances = [new TtsUtterance(SpeakerRole::AGENT, $text)];
        $before = TtsSourceDigest::for(TtsOutputType::Mixed, $utterances);

        $chunker = new TtsTextChunker();
        $chunks = $chunker->split($text, 300);

        $this->assertGreaterThan(1, count($chunks), 'The fixture must actually be split for this to prove anything.');
        $this->assertSame($before, TtsSourceDigest::for(TtsOutputType::Mixed, $utterances));
    }
}
