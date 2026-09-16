<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The provider enum, and the one place the legacy NULL rule lives.
 *
 * The storage values are asserted literally because they are written into `transcription_provider` and
 * pinned by a `CHECK` constraint. Renaming a case without a migration would make every existing row
 * unreadable, so the test exists to make that rename fail here rather than in production.
 */
final class TranscriptionProviderTest extends TestCase
{
    public function testTheStorageValuesAreTheOnesTheDatabaseCheckAllows(): void
    {
        $this->assertSame('WHISPER', TranscriptionProvider::Whisper->value);
        $this->assertSame('DEEPGRAM', TranscriptionProvider::Deepgram->value);
    }

    public function testEveryCaseIsOffered(): void
    {
        $this->assertSame(
            TranscriptionProvider::cases(),
            TranscriptionProvider::all(),
            'all() must offer every case, or a provider would exist that nothing can select.',
        );
    }

    /**
     * Null rather than a default, and the distinction is the point: a stored NULL is legacy Whisper,
     * but a *posted* unknown is a rejected form. A silent default would turn an invalid selection into
     * an accepted one.
     */
    public function testAnUnrecognisedValueDecodesToNullRatherThanADefault(): void
    {
        $this->assertNull(TranscriptionProvider::fromStorage('GOOGLE'));
        $this->assertNull(TranscriptionProvider::fromStorage(''));
        $this->assertNull(TranscriptionProvider::fromStorage('whisper'), 'Decoding is case-sensitive.');
        $this->assertNull(TranscriptionProvider::fromStorage(null));
    }

    public function testAKnownValueDecodes(): void
    {
        $this->assertSame(TranscriptionProvider::Deepgram, TranscriptionProvider::fromStorage('DEEPGRAM'));
    }

    /** Every provider needs both labels; a missing one would render as an empty cell. */
    public function testEveryProviderIsNamedForAPersonAndForATableCell(): void
    {
        foreach (TranscriptionProvider::all() as $provider) {
            $this->assertNotSame('', $provider->label());
            $this->assertNotSame('', $provider->shortLabel());
        }
    }

    public function testAJobWithNoStoredProviderReadsAsWhisper(): void
    {
        $this->assertSame(
            TranscriptionProvider::Whisper,
            $this->job(null)->transcriptionProvider(),
            'Every job predating provider selection was produced by whisper.cpp.',
        );
    }

    public function testAJobKeepsTheProviderItWasQueuedWith(): void
    {
        $this->assertSame(
            TranscriptionProvider::Deepgram,
            $this->job(TranscriptionProvider::Deepgram)->transcriptionProvider(),
        );
    }

    private function job(?TranscriptionProvider $provider): TranscriptionJob
    {
        return new TranscriptionJob(
            id: 1,
            publicId: 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
            uploadedByAdminId: 1,
            uploadedByUsername: 'admin',
            status: JobStatus::COMPLETED,
            stage: null,
            originalFilename: 'call.wav',
            storedAudioPath: null,
            retainedAudioPath: null,
            durationSeconds: 12.0,
            transcript: 'hello',
            detectedLanguage: 'en',
            errorMessage: null,
            agentText: null,
            customerText: null,
            speakerSegmentsJson: null,
            speakerSeparationStatus: null,
            speakerSeparationMethod: null,
            speakerRoleConfidence: null,
            createdAt: new DateTimeImmutable('2026-09-15 10:00:00'),
            startedAt: null,
            completedAt: null,
            expiresAt: null,
            transcriptionProviderOrNull: $provider,
        );
    }
}
