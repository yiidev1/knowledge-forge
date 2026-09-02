<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\AudioToText;

use App\AudioToText\Domain\ProcessingStage;
use App\AudioToText\Domain\QueueSummary;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\Speaker\SpeakerSeparatedTranscript;
use App\AudioToText\Domain\SpeakerSeparationStatus;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use Closure;
use DateTimeImmutable;
use RuntimeException;

/**
 * The real repository, except that the Nth `create()` throws.
 *
 * There is no other honest way to test what survives a half-written pair: the guarantee is that the
 * parent and every child share one transaction, and proving it needs a failure *after* the first
 * insert has already happened. Every other method forwards untouched, so the rows this writes are
 * exactly the rows the real code would write.
 */
final class FailingJobRepository implements TranscriptionJobRepositoryInterface
{
    private int $created = 0;

    public function __construct(
        private readonly TranscriptionJobRepositoryInterface $inner,
        private readonly int $failAfter,
    ) {}

    public function create(
        string $publicId,
        int $uploadedByAdminId,
        string $originalFilename,
        string $storedAudioPath,
        ?float $durationSeconds,
        ?DateTimeImmutable $expiresAt,
        ?int $conversationId = null,
        ?SourceRole $sourceRole = null,
    ): string {
        if ($this->created >= $this->failAfter) {
            throw new RuntimeException('Simulated failure writing child ' . ($this->created + 1) . '.');
        }

        ++$this->created;

        return $this->inner->create(
            $publicId,
            $uploadedByAdminId,
            $originalFilename,
            $storedAudioPath,
            $durationSeconds,
            $expiresAt,
            $conversationId,
            $sourceRole,
        );
    }

    // ---------------------------------------------------------------- everything else forwards

    public function findByPublicId(string $publicId): ?TranscriptionJob
    {
        return $this->inner->findByPublicId($publicId);
    }

    public function findById(int $id): ?TranscriptionJob
    {
        return $this->inner->findById($id);
    }

    public function recent(int $limit, int $previewLength, int $offset = 0): array
    {
        return $this->inner->recent($limit, $previewLength, $offset);
    }

    public function countAll(): int
    {
        return $this->inner->countAll();
    }

    public function summary(): QueueSummary
    {
        return $this->inner->summary();
    }

    public function countActive(): int
    {
        return $this->inner->countActive();
    }

    public function queuePositionOf(int $id): ?int
    {
        return $this->inner->queuePositionOf($id);
    }

    public function existsByPublicId(string $publicId): bool
    {
        return $this->inner->existsByPublicId($publicId);
    }

    public function activePublicIds(): array
    {
        return $this->inner->activePublicIds();
    }

    public function enqueueExclusively(Closure $work): ?string
    {
        return $this->inner->enqueueExclusively($work);
    }

    public function claimNextQueued(int $candidates = 10): ?TranscriptionJob
    {
        return $this->inner->claimNextQueued($candidates);
    }

    public function markStage(int $id, ProcessingStage $stage): void
    {
        $this->inner->markStage($id, $stage);
    }

    public function markTranscribed(int $id, string $transcript, ?string $detectedLanguage): void
    {
        $this->inner->markTranscribed($id, $transcript, $detectedLanguage);
    }

    public function markCompleted(
        int $id,
        SpeakerSeparatedTranscript $separation,
        ?string $retainedAudioPath = null,
    ): void {
        $this->inner->markCompleted($id, $separation, $retainedAudioPath);
    }

    public function markCompletedWithProvidedRole(
        int $id,
        SourceRole $sourceRole,
        ?string $retainedAudioPath = null,
    ): void {
        $this->inner->markCompletedWithProvidedRole($id, $sourceRole, $retainedAudioPath);
    }

    public function markFailed(int $id, string $userMessage): void
    {
        $this->inner->markFailed($id, $userMessage);
    }

    public function markCompletedWithoutSeparation(int $id, SpeakerSeparationStatus $status): void
    {
        $this->inner->markCompletedWithoutSeparation($id, $status);
    }

    public function findStale(int $staleAfterSeconds): array
    {
        return $this->inner->findStale($staleAfterSeconds);
    }

    public function findExpired(int $limit = 100): array
    {
        return $this->inner->findExpired($limit);
    }

    public function saveReview(
        int $id,
        string $reviewedSegmentsJson,
        ?string $reviewedAgentText,
        ?string $reviewedCustomerText,
        int $reviewedByAdminId,
        int $expectedReviewCount,
    ): bool {
        return $this->inner->saveReview(
            $id,
            $reviewedSegmentsJson,
            $reviewedAgentText,
            $reviewedCustomerText,
            $reviewedByAdminId,
            $expectedReviewCount,
        );
    }

    public function confirmRoles(
        int $id,
        string $segmentsJson,
        string $agentText,
        string $customerText,
        int $confirmedByAdminId,
        int $expectedReviewCount,
    ): bool {
        return $this->inner->confirmRoles(
            $id,
            $segmentsJson,
            $agentText,
            $customerText,
            $confirmedByAdminId,
            $expectedReviewCount,
        );
    }

    public function clearReview(int $id, int $reviewedByAdminId, int $expectedReviewCount): bool
    {
        return $this->inner->clearReview($id, $reviewedByAdminId, $expectedReviewCount);
    }

    public function delete(int $id): void
    {
        $this->inner->delete($id);
    }
}
