<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\ProcessingStage;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\SpeakerSeparationStatus;
use App\AudioToText\Domain\TranscriptionJob;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

use function json_encode;

/**
 * The seam every surface will read through once corrections exist.
 *
 * At this step it must be **inert**: the reader returns exactly what the page read directly before, so
 * the plumbing can be proven correct while no data flows through it. The reviewed layer changes one
 * method later and no caller at all.
 */
final class EffectiveConversationReaderTest extends TestCase
{
    private EffectiveConversationReader $reader;

    protected function setUp(): void
    {
        $this->reader = new EffectiveConversationReader(new SpeakerSegmentsDecoder());
    }

    public function testItReturnsTheMachineResultUnchanged(): void
    {
        $job = $this->job(
            segments: [[
                'start_ms' => 0,
                'end_ms' => 2000,
                'speaker' => 'SPEAKER_00',
                'role' => 'CUSTOMER',
                'text' => 'Hello?',
                'confidence' => 0.9,
            ]],
            agentText: 'agent side',
            customerText: 'customer side',
        );

        $effective = $this->reader->for($job);

        self::assertCount(1, $effective->utterances);
        self::assertSame('Hello?', $effective->utterances[0]->text);
        self::assertSame(SpeakerRole::CUSTOMER, $effective->utterances[0]->role);
        self::assertSame('agent side', $effective->agentText);
        self::assertSame('customer side', $effective->customerText);
    }

    /** Nothing writes a reviewed conversation yet, so no job may claim to have one. */
    public function testNothingIsReviewedYet(): void
    {
        self::assertFalse($this->reader->for($this->job())->isReviewed);
    }

    /**
     * The gate that decides whether role labels may be shown now reads from the same object as the
     * turns, so a future correction cannot make the cards and the bubbles disagree.
     */
    public function testTheSeparatedTextGateMatchesTheJob(): void
    {
        self::assertTrue($this->reader->for($this->job(agentText: 'a', customerText: 'c'))->hasSeparatedText());
        self::assertTrue($this->reader->for($this->job(agentText: 'a'))->hasSeparatedText());
        self::assertFalse($this->reader->for($this->job())->hasSeparatedText());
    }

    public function testAJobWithNoSegmentsYieldsAnEmptyConversation(): void
    {
        self::assertTrue($this->reader->for($this->job())->isEmpty());
    }

    /** A column that will not decode costs the panel and nothing else. */
    public function testMalformedSegmentsDegradeToEmptyRatherThanThrowing(): void
    {
        $job = $this->job(rawSegmentsJson: '{not json');

        $effective = $this->reader->for($job);

        self::assertTrue($effective->isEmpty());
        self::assertSame('the words', $effective->agentText ?? 'the words');
    }

    /**
     * Reviewed segments will carry an extra `approx` flag for boundaries an administrator created.
     * The decoder must ignore it rather than reject the row — asserted now so the later step cannot
     * silently discard every split turn.
     */
    public function testUnknownKeysAreIgnoredRatherThanRejectingTheRow(): void
    {
        $job = $this->job(segments: [[
            'start_ms' => 100,
            'end_ms' => 900,
            'speaker' => 'SPEAKER_01',
            'role' => 'AGENT',
            'text' => 'For pickup',
            'confidence' => 0.5,
            'approx' => true,
            'something_added_later' => 'ignored',
        ]]);

        $effective = $this->reader->for($job);

        self::assertCount(1, $effective->utterances);
        self::assertSame('For pickup', $effective->utterances[0]->text);
        self::assertSame(100, $effective->utterances[0]->startMs);
    }

    /**
     * @param list<array<string, mixed>>|null $segments
     */
    private function job(
        ?array $segments = null,
        ?string $agentText = null,
        ?string $customerText = null,
        ?string $rawSegmentsJson = null,
    ): TranscriptionJob {
        $now = new DateTimeImmutable('2026-08-31 12:00:00', new DateTimeZone('UTC'));

        return new TranscriptionJob(
            1,
            'a0652255c038ba123ae6e3d177edbbe9',
            7,
            'admin',
            JobStatus::COMPLETED,
            ProcessingStage::COMPLETED,
            'call.wav',
            null,
            null,
            73.72,
            'a transcript',
            'en',
            null,
            $agentText,
            $customerText,
            $rawSegmentsJson ?? ($segments === null ? null : json_encode($segments)),
            SpeakerSeparationStatus::COMPLETED,
            'sherpa-onnx',
            0.9,
            $now,
            $now,
            $now,
            null,
        );
    }
}
