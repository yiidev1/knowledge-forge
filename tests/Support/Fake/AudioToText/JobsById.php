<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\AudioToText;

use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\ProcessingStage;
use App\AudioToText\Domain\QueueSummary;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\Speaker\SeparationReviewReason;
use App\AudioToText\Domain\Speaker\SpeakerSeparatedTranscript;
use App\AudioToText\Domain\SpeakerSeparationStatus;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Domain\TranscriptionProvider;
use Closure;
use DateTimeImmutable;
use RuntimeException;

/**
 * Several jobs, answered by id and by public id.
 *
 * {@see OneJobRepository} holds one, which is all a file-serving action ever looks up. A combined
 * conversation is assembled from **two** rows — the Customer channel and the Agent channel — and the
 * whole point of the projection is that they are separate rows with separate versions, so a fake that
 * could only hold one of them would make the thing under test untestable.
 *
 * Everything else throws, in the spirit of its sibling: a test that starts depending on another method
 * fails loudly rather than quietly reading a default nobody thought about.
 *
 * @psalm-suppress MissingImmutableAnnotation the interface is not readonly
 */
final class JobsById implements TranscriptionJobRepositoryInterface
{
    /** @var array<int, TranscriptionJob> */
    private array $jobs = [];

    public function __construct(TranscriptionJob ...$jobs)
    {
        foreach ($jobs as $job) {
            $this->jobs[$job->id] = $job;
        }
    }

    public function findById(int $id): ?TranscriptionJob
    {
        return $this->jobs[$id] ?? null;
    }

    public function findByPublicId(string $publicId): ?TranscriptionJob
    {
        foreach ($this->jobs as $job) {
            if ($job->publicId === $publicId) {
                return $job;
            }
        }

        return null;
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

    public function create(string $publicId, int $uploadedByAdminId, string $originalFilename, ?string $storedAudioPath, ?float $durationSeconds, ?DateTimeImmutable $expiresAt, ?int $conversationId = null, ?SourceRole $sourceRole = null, ?TranscriptionProvider $transcriptionProvider = null, JobStatus $status = JobStatus::QUEUED, ?string $retainedAudioPath = null): string
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function claimNextQueued(int $candidates = 10): ?TranscriptionJob
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function requestTranscription(int $id, TranscriptionProvider $provider): bool
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function recordWorkspaceCopy(int $id, string $storedName): void
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

    public function markCompleted(int $id, SpeakerSeparatedTranscript $separation, ?string $retainedAudioPath = null): void
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function markCompletedWithProvidedRole(int $id, SourceRole $sourceRole, ?string $retainedAudioPath = null, ?string $segmentsJson = null): void
    {
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

    public function saveReview(int $id, string $reviewedSegmentsJson, ?string $reviewedAgentText, ?string $reviewedCustomerText, int $reviewedByAdminId, int $expectedReviewCount): bool
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function confirmRoles(int $id, string $segmentsJson, string $agentText, string $customerText, int $confirmedByAdminId, int $expectedReviewCount): bool
    {
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

    public function needingSpeakerReviewDiagnosis(int $limit): array
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function recordSpeakerReviewDiagnosis(int $id, SeparationReviewReason $reason): void
    {
        throw new RuntimeException('Not used by these tests.');
    }
}
