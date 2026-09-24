<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\AudioToText;

use App\AudioToText\Domain\ProcessingStage;
use App\AudioToText\Domain\QueueSummary;
use App\AudioToText\Domain\Speaker\SpeakerSeparatedTranscript;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\SpeakerSeparationStatus;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Domain\TranscriptionProvider;
use Closure;
use DateTimeImmutable;
use RuntimeException;

/**
 * The one lookup a file-serving action performs, answered from a single job.
 *
 * Serving a recording's bytes is a lookup and a read — no queue, no writes — so this answers
 * {@see findByPublicId()} and nothing else. Every other method throws, in the spirit of
 * {@see FixedRecordingTypes}: a test that starts depending on one of them fails loudly rather than
 * quietly reading a default that was never thought about.
 *
 * @psalm-suppress MissingImmutableAnnotation the interface is not readonly
 */
final class OneJobRepository implements TranscriptionJobRepositoryInterface
{
    public function __construct(private ?TranscriptionJob $job) {}

    /** Matches on the public id, so asking for somebody else's recording finds nothing. */
    public function findByPublicId(string $publicId): ?TranscriptionJob
    {
        return $this->job !== null && $this->job->publicId === $publicId ? $this->job : null;
    }

    public function findById(int $id): ?TranscriptionJob
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function recent(int $limit, int $previewLength, int $offset = 0): array
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function countAll(): int
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function summary(): QueueSummary
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function countActive(): int
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function queuePositionOf(int $id): ?int
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function existsByPublicId(string $publicId): bool
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function activePublicIds(): array
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function enqueueExclusively(Closure $work): ?string
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function create(
        string $publicId,
        int $uploadedByAdminId,
        string $originalFilename,
        string $storedAudioPath,
        ?float $durationSeconds,
        ?DateTimeImmutable $expiresAt,
        ?int $conversationId = null,
        ?SourceRole $sourceRole = null,
        ?TranscriptionProvider $transcriptionProvider = null,
    ): string {
        throw new RuntimeException('Not used by these tests.');
    }

    public function claimNextQueued(int $candidates = 10): ?TranscriptionJob
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function markStage(int $id, ProcessingStage $stage): void
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function markTranscribed(int $id, string $transcript, ?string $detectedLanguage): void
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function markCompleted(
        int $id,
        SpeakerSeparatedTranscript $separation,
        ?string $retainedAudioPath = null,
    ): void {
        throw new RuntimeException('Not used by these tests.');
    }

    public function markCompletedWithProvidedRole(
        int $id,
        SourceRole $sourceRole,
        ?string $retainedAudioPath = null,
    ): void {
        throw new RuntimeException('Not used by these tests.');
    }

    public function markFailed(int $id, string $userMessage): void
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function markCompletedWithoutSeparation(int $id, SpeakerSeparationStatus $status): void
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function findStale(int $staleAfterSeconds): array
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function findExpired(int $limit = 100): array
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function saveReview(
        int $id,
        string $reviewedSegmentsJson,
        ?string $reviewedAgentText,
        ?string $reviewedCustomerText,
        int $reviewedByAdminId,
        int $expectedReviewCount,
    ): bool {
        throw new RuntimeException('Not used by these tests.');
    }

    public function confirmRoles(
        int $id,
        string $segmentsJson,
        string $agentText,
        string $customerText,
        int $confirmedByAdminId,
        int $expectedReviewCount,
    ): bool {
        throw new RuntimeException('Not used by these tests.');
    }

    public function clearReview(int $id, int $reviewedByAdminId, int $expectedReviewCount): bool
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function delete(int $id): void
    {
        throw new RuntimeException('Not used by these tests.');
    }
}
