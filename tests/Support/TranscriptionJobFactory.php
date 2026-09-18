<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\ProcessingStage;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\SpeakerSeparationStatus;
use App\AudioToText\Domain\TranscriptionJob;
use DateTimeImmutable;
use DateTimeZone;

use function json_encode;

/**
 * Builds transcription jobs for tests, in the two shapes the pipeline actually produces.
 *
 * `TranscriptionJob` has thirty-one constructor parameters, so a test that wants "a completed mixed
 * recording with these two turns" would otherwise spend twenty lines saying so and would break every
 * time a field is added. The two named constructors below are the shapes that exist in reality:
 *
 *  - {@see mixedRecording()} — one file, diarized, roles inferred and published.
 *  - {@see separateRecording()} — one side of a pair, never diarized, role declared at upload.
 *
 * The second is the one worth having a factory for. Its defaults encode a fact that is easy to get
 * wrong by hand and that broke an earlier draft of the AI-audio feature: a separate child has
 * `speaker_separation_status` NULL and `roles_confirmed_at` NULL, so `rolesConfirmed()` returns **false**
 * for it — forever, by design, because nothing was inferred and so nothing is claimed.
 */
final class TranscriptionJobFactory
{
    /**
     * A mixed recording: one file with both people on it, diarized.
     *
     * @param list<array{start_ms?: int, end_ms?: int, speaker?: string, role: string, text: string, confidence?: float}>|null $segments
     * @param list<array{start_ms?: int, end_ms?: int, speaker?: string, role: string, text: string, confidence?: float}>|null $reviewedSegments
     */
    public static function mixedRecording(
        ?array $segments = null,
        ?string $agentText = null,
        ?string $customerText = null,
        ?array $reviewedSegments = null,
        ?string $reviewedAgentText = null,
        ?string $reviewedCustomerText = null,
        ?string $transcript = 'a transcript',
        JobStatus $status = JobStatus::COMPLETED,
        // COMPLETED is what makes the roles publishable without a human confirmation, which is the
        // ordinary state of a call the pipeline was confident about.
        ?SpeakerSeparationStatus $separationStatus = SpeakerSeparationStatus::COMPLETED,
        ?DateTimeImmutable $rolesConfirmedAt = null,
        int $reviewCount = 0,
        int $id = 1,
        string $publicId = 'a0652255c038ba123ae6e3d177edbbe9',
    ): TranscriptionJob {
        return self::build(
            id: $id,
            publicId: $publicId,
            status: $status,
            transcript: $transcript,
            agentText: $agentText,
            customerText: $customerText,
            segmentsJson: $segments === null ? null : (string) json_encode($segments),
            separationStatus: $separationStatus,
            reviewedSegmentsJson: $reviewedSegments === null ? null : (string) json_encode($reviewedSegments),
            reviewedAgentText: $reviewedAgentText,
            reviewedCustomerText: $reviewedCustomerText,
            rolesConfirmedAt: $rolesConfirmedAt,
            reviewCount: $reviewCount,
            sourceRole: SourceRole::Common,
        );
    }

    /**
     * One side of a separate Customer + Agent upload.
     *
     * Mirrors `markCompletedWithProvidedRole()` exactly: the transcript is copied into the matching role
     * column, every separation column stays NULL, and no confirmation timestamp is ever set. The role is
     * a fact the administrator supplied, not a conclusion the machine reached.
     */
    public static function separateRecording(
        SourceRole $role,
        string $transcript = 'one side of the call',
        JobStatus $status = JobStatus::COMPLETED,
        int $id = 1,
        string $publicId = 'b1763366d149cb234bf7f4e288fecca0',
    ): TranscriptionJob {
        return self::build(
            id: $id,
            publicId: $publicId,
            status: $status,
            transcript: $transcript,
            agentText: $role === SourceRole::Agent ? $transcript : null,
            customerText: $role === SourceRole::Customer ? $transcript : null,
            segmentsJson: null,
            // NULL, not NOT_SUPPORTED: nothing was attempted, so there is no outcome to record.
            separationStatus: null,
            reviewedSegmentsJson: null,
            reviewedAgentText: null,
            reviewedCustomerText: null,
            rolesConfirmedAt: null,
            reviewCount: 0,
            sourceRole: $role,
        );
    }

    private static function build(
        int $id,
        string $publicId,
        JobStatus $status,
        ?string $transcript,
        ?string $agentText,
        ?string $customerText,
        ?string $segmentsJson,
        ?SpeakerSeparationStatus $separationStatus,
        ?string $reviewedSegmentsJson,
        ?string $reviewedAgentText,
        ?string $reviewedCustomerText,
        ?DateTimeImmutable $rolesConfirmedAt,
        int $reviewCount,
        SourceRole $sourceRole,
    ): TranscriptionJob {
        $now = new DateTimeImmutable('2026-09-18 12:00:00', new DateTimeZone('UTC'));

        return new TranscriptionJob(
            $id,
            $publicId,
            7,
            'admin',
            $status,
            ProcessingStage::COMPLETED,
            'call.wav',
            null,
            'source.wav',
            73.72,
            $transcript,
            'en',
            null,
            $agentText,
            $customerText,
            $segmentsJson,
            $separationStatus,
            $separationStatus === null ? null : 'sherpa-onnx',
            $separationStatus === null ? null : 0.9,
            $now,
            $now,
            $now,
            null,
            $reviewedSegmentsJson,
            $reviewedAgentText,
            $reviewedCustomerText,
            $reviewedSegmentsJson === null ? null : $now,
            $reviewedSegmentsJson === null ? null : 'reviewer',
            $rolesConfirmedAt,
            $reviewCount,
            42,
            $sourceRole,
            null,
        );
    }
}
