<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Application\ChatAvailabilityPolicy;
use App\Chat\Application\ChatUnavailableReason;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\KnowledgeBaseStatus;
use App\KnowledgeBase\Domain\VectorStoreStatus;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseSourceRepository;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The canonical availability decision, exercised as pure logic: an Order58-linked base needs a usable
 * store-profile snapshot AND a usable qualifying document; a plain base needs only a qualifying document;
 * the store profile never satisfies the qualifying requirement; provisioning gates everything; and one
 * store's state can never change another's (isolation).
 */
final class ChatAvailabilityPolicyTest extends Unit
{
    private const KB_A = 100;
    private const KB_B = 200;

    private InMemoryDocumentRepository $documents;
    private InMemoryKnowledgeBaseSourceRepository $sources;

    protected function _before(): void
    {
        $this->documents = new InMemoryDocumentRepository();
        $this->sources = new InMemoryKnowledgeBaseSourceRepository();
    }

    public function testOrder58BaseNeedsBothProfileAndQualifyingDocument(): void
    {
        $this->sources->linkToOrder58(self::KB_A, 1491);
        $this->documents->setUsableStoreProfile(self::KB_A, true);
        $this->documents->setUsableQualifyingDocument(self::KB_A, true);

        assertTrue($this->policy()->isAvailable($this->readyKb(self::KB_A)));
        assertSame(ChatUnavailableReason::Available, $this->policy()->getUnavailableReason($this->readyKb(self::KB_A)));
    }

    public function testOrder58BaseWithProfileButNoQualifyingDocumentIsUnavailable(): void
    {
        $this->sources->linkToOrder58(self::KB_A, 1491);
        $this->documents->setUsableStoreProfile(self::KB_A, true);
        $this->documents->setUsableQualifyingDocument(self::KB_A, false);

        assertFalse($this->policy()->isAvailable($this->readyKb(self::KB_A)));
        assertSame(ChatUnavailableReason::Order58NotReady, $this->policy()->getUnavailableReason($this->readyKb(self::KB_A)));
    }

    public function testOrder58BaseWithQualifyingButNoProfileIsUnavailable(): void
    {
        $this->sources->linkToOrder58(self::KB_A, 1491);
        $this->documents->setUsableStoreProfile(self::KB_A, false);
        $this->documents->setUsableQualifyingDocument(self::KB_A, true);

        assertSame(ChatUnavailableReason::Order58NotReady, $this->policy()->getUnavailableReason($this->readyKb(self::KB_A)));
    }

    public function testNonOrder58BaseNeedsOnlyQualifyingDocument(): void
    {
        // No Order58 link; a usable qualifying document alone makes it available (no profile required).
        $this->documents->setUsableQualifyingDocument(self::KB_A, true);

        assertTrue($this->policy()->isAvailable($this->readyKb(self::KB_A)));
    }

    public function testNonOrder58BaseWithoutQualifyingDocumentIsUnavailable(): void
    {
        assertFalse($this->policy()->isAvailable($this->readyKb(self::KB_A)));
        assertSame(ChatUnavailableReason::NoQualifyingDocument, $this->policy()->getUnavailableReason($this->readyKb(self::KB_A)));
    }

    public function testSourceInactiveOrder58BaseIsUnavailableEvenWhenReadyWithDocuments(): void
    {
        // Linked to an INACTIVE Order58 store; everything else is ready. Source-inactive outranks all.
        $this->sources->linkToOrder58(self::KB_A, 1491, active: false);
        $this->documents->setUsableStoreProfile(self::KB_A, true);
        $this->documents->setUsableQualifyingDocument(self::KB_A, true);

        assertFalse($this->policy()->isAvailable($this->readyKb(self::KB_A)));
        assertSame(ChatUnavailableReason::SourceInactive, $this->policy()->getUnavailableReason($this->readyKb(self::KB_A)));
    }

    public function testReadyStatusWithNullVectorStoreIdIsNotProvisioned(): void
    {
        // A Ready status but a missing vector-store id (invariant violated) must not be treated as available.
        $this->documents->setUsableQualifyingDocument(self::KB_A, true);

        $kb = new KnowledgeBase(
            self::KB_A,
            'Store',
            'store',
            null,
            null,
            null, // openai_vector_store_id
            VectorStoreStatus::Ready,
            null,
            KnowledgeBaseStatus::Active,
            new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')),
        );

        assertSame(ChatUnavailableReason::NotProvisioned, $this->policy()->getUnavailableReason($kb));
    }

    public function testUnprovisionedBaseIsUnavailableRegardlessOfDocuments(): void
    {
        $this->sources->linkToOrder58(self::KB_A, 1491);
        $this->documents->setUsableStoreProfile(self::KB_A, true);
        $this->documents->setUsableQualifyingDocument(self::KB_A, true);

        assertSame(ChatUnavailableReason::NotProvisioned, $this->policy()->getUnavailableReason($this->unprovisionedKb(self::KB_A)));
    }

    public function testArchivedBaseIsUnavailable(): void
    {
        $this->documents->setUsableQualifyingDocument(self::KB_A, true);

        assertFalse($this->policy()->isAvailable($this->archivedKb(self::KB_A)));
    }

    public function testStoreIsolation_storeAUnaffectedByStoreBDocuments(): void
    {
        // Store B has a usable qualifying document; store A has none. Only B is available.
        $this->documents->setUsableQualifyingDocument(self::KB_B, true);

        assertTrue($this->policy()->isAvailable($this->readyKb(self::KB_B)));
        assertFalse($this->policy()->isAvailable($this->readyKb(self::KB_A)));
    }

    public function testStoreIsolation_storeAProfileDoesNotSatisfyStoreB(): void
    {
        $this->sources->linkToOrder58(self::KB_A, 1491);
        $this->sources->linkToOrder58(self::KB_B, 1491); // same external store id — must not leak
        $this->documents->setUsableStoreProfile(self::KB_A, true);
        $this->documents->setUsableQualifyingDocument(self::KB_A, true);
        // B has nothing.

        assertTrue($this->policy()->isAvailable($this->readyKb(self::KB_A)));
        assertFalse($this->policy()->isAvailable($this->readyKb(self::KB_B)));
    }

    public function testAssertAvailableThrowsTheRightReason(): void
    {
        $this->sources->linkToOrder58(self::KB_A, 1491);
        $this->documents->setUsableStoreProfile(self::KB_A, false);

        $threw = false;
        try {
            $this->policy()->assertAvailable($this->readyKb(self::KB_A));
        } catch (ChatUnavailable $e) {
            $threw = true;
            assertSame('chat_unavailable', $e->errorCode());
        }
        assertTrue($threw, 'assertAvailable must throw ChatUnavailable when unavailable.');
    }

    public function testAssertAvailablePassesWhenAvailable(): void
    {
        $this->documents->setUsableQualifyingDocument(self::KB_A, true);

        $this->policy()->assertAvailable($this->readyKb(self::KB_A));
        assertTrue(true); // reached without throwing
    }

    private function policy(): ChatAvailabilityPolicy
    {
        return new ChatAvailabilityPolicy($this->documents, $this->sources);
    }

    private function readyKb(int $id): KnowledgeBase
    {
        return $this->kb($id, KnowledgeBaseStatus::Active, VectorStoreStatus::Ready);
    }

    private function unprovisionedKb(int $id): KnowledgeBase
    {
        return $this->kb($id, KnowledgeBaseStatus::Active, VectorStoreStatus::Pending);
    }

    private function archivedKb(int $id): KnowledgeBase
    {
        return $this->kb($id, KnowledgeBaseStatus::Archived, VectorStoreStatus::Ready);
    }

    private function kb(int $id, KnowledgeBaseStatus $status, VectorStoreStatus $vs): KnowledgeBase
    {
        $now = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));

        return new KnowledgeBase(
            $id,
            'Store ' . $id,
            'store-' . $id,
            null,
            null,
            'vs_' . $id,
            $vs,
            null,
            $status,
            $now,
            $now,
        );
    }
}
