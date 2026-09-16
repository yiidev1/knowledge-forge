<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\Speaker\SpeakerTranscriptAligner;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\Speaker\SpeakerSegment;
use App\AudioToText\Domain\Speaker\TranscriptToken;
use App\AudioToText\Domain\Transcription\DeepgramKeyterms;
use App\AudioToText\Domain\Transcription\TranscriptionRequest;
use App\AudioToText\Infrastructure\Transcription\DeepgramEngine;
use App\Tests\Support\AudioToTextSettingsFactory;
use App\Tests\Support\RecordingHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function file_put_contents;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The bug that shipped, pinned end to end.
 *
 * `DeepgramEngine` emitted bare words while {@see TranscriptToken} requires a word-initial token to
 * carry a leading space. {@see SpeakerTranscriptAligner} reads that space twice — to join tokens into
 * utterance text, and to decide whether a token continues the previous word and must inherit its
 * speaker. Omitting it produced **two** symptoms from **one** cause, on a real 206-second two-party
 * call:
 *
 *   1. text ran together:  "Yes.CanIcanIorderalargeshrimp…"
 *   2. 23 alternating speaker turns collapsed into 1, so roles could not be confirmed at all.
 *
 * {@see DeepgramEngineTest::testEveryTokenBeginsWithWhitespace} asserts the engine's half of the
 * contract. This file asserts the half that actually matters to a reader: that real Deepgram output,
 * put through the **real aligner** against a genuine two-speaker diarization, comes out as readable,
 * correctly-attributed conversation.
 *
 * The aligner is used unmodified and is not under test here — it was always right. What is under test
 * is that the engine now feeds it something it can read.
 *
 * No network call is made: the Deepgram response is a fixture handed to a doubled PSR-18 client.
 */
final class DeepgramTokenContractTest extends TestCase
{
    private ?string $wav = null;

    protected function tearDown(): void
    {
        if ($this->wav !== null) {
            @unlink($this->wav);
            $this->wav = null;
        }
    }

    // ------------------------------------------------------------------ symptom 1: the spacing

    /**
     * The words a reviewer reads are separated, and identical to what Deepgram sent.
     *
     * Asserted on the aligner's output rather than on the tokens, because that is the string the
     * review page, the role columns and the downloadable transcript are all built from.
     */
    public function testAlignedTextIsNormallySpaced(): void
    {
        $utterances = $this->align($this->twoSpeakerSegments());

        $joined = implode(' ', array_map(
            static fn($utterance): string => $utterance->text,
            $utterances,
        ));

        $this->assertSame('Can I order a large shrimp fried rice?', $joined);
    }

    /** The precise shape of the shipped defect, named so a regression is unmistakable. */
    public function testWordsAreNeverRunTogether(): void
    {
        foreach ($this->align($this->twoSpeakerSegments()) as $utterance) {
            $this->assertStringNotContainsString('CanI', $utterance->text);
            $this->assertStringNotContainsString('largeshrimp', $utterance->text);
            $this->assertStringNotContainsString('friedrice', $utterance->text);
        }
    }

    /** Punctuation stays attached to its own word rather than drifting onto the next. */
    public function testPunctuationStaysWithItsWord(): void
    {
        $texts = array_map(
            static fn($utterance): string => $utterance->text,
            $this->align($this->twoSpeakerSegments()),
        );

        $this->assertStringEndsWith('rice?', $texts[count($texts) - 1]);
    }

    // ------------------------------------------------------------------ symptom 2: the collapse

    /**
     * **The load-bearing test.** A valid two-speaker diarization must survive alignment.
     *
     * The fixture gives four utterances alternating between two speakers, exactly as Sherpa produced
     * for the real call. Before the fix this returned a single utterance attributed to whichever
     * speaker happened to own the first word.
     */
    public function testAValidTwoSpeakerDiarizationIsNotCollapsedIntoOne(): void
    {
        $utterances = $this->align($this->twoSpeakerSegments());

        $speakers = array_unique(array_map(
            static fn($utterance): string => $utterance->speaker,
            $utterances,
        ));

        $this->assertCount(2, $speakers, 'Both speakers must survive alignment.');
        $this->assertGreaterThan(1, count($utterances), 'A two-party call is more than one turn.');
    }

    /** Turn boundaries land where the diarizer put them, not where the first word happened to fall. */
    public function testEachTurnIsAttributedToTheSpeakerWhoWasTalking(): void
    {
        $actual = array_map(
            static fn($utterance): array => [$utterance->speaker, $utterance->text],
            $this->align($this->twoSpeakerSegments()),
        );

        $this->assertSame([
            ['SPEAKER_01', 'Can I'],
            ['SPEAKER_00', 'order a'],
            ['SPEAKER_01', 'large shrimp'],
            ['SPEAKER_00', 'fried rice?'],
        ], $actual);
    }

    /**
     * The protection the leading space exists for is still in force.
     *
     * A token that genuinely continues the previous word — whisper.cpp emits these, Deepgram does not —
     * must still inherit its speaker rather than starting a turn mid-word. Proving it here means the
     * fix restored the contract rather than defeating the rule that depends on it.
     */
    public function testAContinuationTokenStillInheritsItsSpeaker(): void
    {
        $segments = [
            new SpeakerSegment(0, 1000, 'SPEAKER_01'),
            // A turn change landing in the middle of "sesame".
            new SpeakerSegment(1001, 3000, 'SPEAKER_00'),
        ];

        $tokens = [
            new TranscriptToken(0, 900, ' ses'),
            new TranscriptToken(1100, 1400, 'ame'),   // no leading space: same word
            new TranscriptToken(1500, 2000, ' chicken'),
        ];

        $utterances = (new SpeakerTranscriptAligner())->align($tokens, $segments, 1500)->utterances;

        $this->assertSame('sesame', $utterances[0]->text, 'One word must not be split across speakers.');
        $this->assertSame('SPEAKER_01', $utterances[0]->speaker);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Real engine, real fixture, real aligner.
     *
     * @param list<SpeakerSegment> $segments
     *
     * @return list<\App\AudioToText\Domain\Speaker\SpeakerUtterance>
     */
    private function align(array $segments): array
    {
        $result = $this->recognise();

        return (new SpeakerTranscriptAligner())->align($result->tokens, $segments, 1500)->utterances;
    }

    /**
     * Two speakers alternating, the shape Sherpa produced for the reference call.
     *
     * @return list<SpeakerSegment>
     */
    private function twoSpeakerSegments(): array
    {
        return [
            new SpeakerSegment(0, 700, 'SPEAKER_01'),
            new SpeakerSegment(1700, 2300, 'SPEAKER_00'),
            new SpeakerSegment(3600, 4900, 'SPEAKER_01'),
            new SpeakerSegment(8300, 9800, 'SPEAKER_00'),
        ];
    }

    /**
     * @throws AudioTranscriptionException
     */
    private function recognise(): \App\AudioToText\Domain\Transcription\AudioTranscriptionResult
    {
        $psr7 = new HttpFactory();
        $client = new RecordingHttpClient(
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($this->body())),
        );

        $engine = new DeepgramEngine(
            AudioToTextSettingsFactory::create(deepgramApiKey: 'test-key-not-a-real-credential'),
            $client,
            $psr7,
            $psr7,
        );

        return $engine->recognise(new TranscriptionRequest($this->wavPath(), DeepgramKeyterms::none()));
    }

    /** A real file, because the engine opens it. Nothing here reaches the network. */
    private function wavPath(): string
    {
        if ($this->wav === null) {
            $this->wav = (string) tempnam(sys_get_temp_dir(), 'a2t-contract-');
            file_put_contents($this->wav, 'RIFF....WAVEfmt ');
        }

        return $this->wav;
    }

    /**
     * Deepgram's documented shape: a spaced `transcript`, and `words[]` of BARE words.
     *
     * The bare words are the point — this is what Deepgram really sends, and what the engine has to
     * translate. Timings place each word inside one of the diarization segments above.
     *
     * @return array<string, mixed>
     */
    private function body(): array
    {
        $words = [
            ['Can', 0.0, 0.4],
            ['I', 0.41, 0.7],
            ['order', 1.7, 2.1],
            ['a', 2.15, 2.3],
            ['large', 3.6, 4.1],
            ['shrimp', 4.2, 4.9],
            ['fried', 8.3, 8.9],
            ['rice?', 9.0, 9.8],
        ];

        $encoded = [];
        foreach ($words as [$word, $start, $end]) {
            $encoded[] = [
                'word' => rtrim($word, '?'),
                'punctuated_word' => $word,
                'start' => $start,
                'end' => $end,
            ];
        }

        return [
            'results' => [
                'channels' => [
                    [
                        'detected_language' => 'en',
                        'alternatives' => [
                            [
                                // Spaced, exactly as the real API returns it — which is why the raw
                                // transcript column always looked correct while the turns did not.
                                'transcript' => 'Can I order a large shrimp fried rice?',
                                'words' => $encoded,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
