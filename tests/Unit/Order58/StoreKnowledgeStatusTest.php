<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\KnowledgeBase\Domain\VectorStoreStatus;
use App\Order58\Domain\StoreKnowledgeStatus;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;

/**
 * The single friendly store status and, crucially, its priority: Ready beats everything (chat already has
 * usable knowledge), then Processing (something is being prepared), then Failed, then No knowledge.
 */
final class StoreKnowledgeStatusTest extends Unit
{
    public function testReadyDocumentWinsEvenWhenOthersAreProcessingOrFailed(): void
    {
        // One ready document + another still processing + another failed + KB still provisioning → Ready.
        assertSame(
            StoreKnowledgeStatus::Ready,
            StoreKnowledgeStatus::fromFlags(
                hasReadyDocument: true,
                hasDocumentInProgress: true,
                hasFailedDocument: true,
                vectorStoreStatus: VectorStoreStatus::Provisioning,
            ),
        );
    }

    public function testProcessingWhenADocumentIsInProgressAndNoneReady(): void
    {
        assertSame(
            StoreKnowledgeStatus::Processing,
            StoreKnowledgeStatus::fromFlags(false, true, false, VectorStoreStatus::Ready),
        );
    }

    public function testProcessingWhileTheKnowledgeBaseIsBeingPrepared(): void
    {
        // No documents at all yet, but the vector store is still being provisioned → Processing, not No knowledge.
        assertSame(
            StoreKnowledgeStatus::Processing,
            StoreKnowledgeStatus::fromFlags(false, false, false, VectorStoreStatus::Pending),
        );
        assertSame(
            StoreKnowledgeStatus::Processing,
            StoreKnowledgeStatus::fromFlags(false, false, false, VectorStoreStatus::Provisioning),
        );
    }

    public function testFailedOnlyWhenNothingReadyOrInProgress(): void
    {
        // A failed document, nothing ready or in progress, KB ready → Failed.
        assertSame(
            StoreKnowledgeStatus::Failed,
            StoreKnowledgeStatus::fromFlags(false, false, true, VectorStoreStatus::Ready),
        );
        // KB preparation failed, no documents → Failed.
        assertSame(
            StoreKnowledgeStatus::Failed,
            StoreKnowledgeStatus::fromFlags(false, false, false, VectorStoreStatus::Failed),
        );
    }

    public function testProcessingBeatsFailed(): void
    {
        // A failed document but another still in progress → Processing (priority 2 over priority 3).
        assertSame(
            StoreKnowledgeStatus::Processing,
            StoreKnowledgeStatus::fromFlags(false, true, true, VectorStoreStatus::Ready),
        );
    }

    public function testNoKnowledgeWhenReadyKbHasNoDocuments(): void
    {
        assertSame(
            StoreKnowledgeStatus::NoKnowledge,
            StoreKnowledgeStatus::fromFlags(false, false, false, VectorStoreStatus::Ready),
        );
    }

    public function testLabels(): void
    {
        assertSame('Ready', StoreKnowledgeStatus::Ready->label());
        assertSame('Processing', StoreKnowledgeStatus::Processing->label());
        assertSame('Failed', StoreKnowledgeStatus::Failed->label());
        assertSame('No knowledge', StoreKnowledgeStatus::NoKnowledge->label());
    }
}
