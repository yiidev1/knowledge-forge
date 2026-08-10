<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Application\RuleChatAvailability;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Application\FindOrCreateThreadService;
use App\Document\Domain\DocumentStatus;
use App\Rules\Application\CommonRulesReadiness;
use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\Tests\Support\Fake\Chat\InMemoryConversationRepository;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseRepository;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class RuleChatAvailabilityAndThreadIsolationTest extends Unit
{
    private const KB = 42;

    public function testUnavailableWithoutIndexedGlobalRule(): void
    {
        $documents = new InMemoryDocumentRepository();
        $kbs = new InMemoryKnowledgeBaseRepository();
        $kbs->seedReady(self::KB, EnsureCommonRulesKnowledgeBaseService::SLUG, 'vs_rules');

        $availability = new RuleChatAvailability(
            new CommonRulesReadiness(new EnsureCommonRulesKnowledgeBaseService($kbs), $documents),
        );

        assertFalse($availability->isAvailable());
        assertNull($availability->readyKnowledgeBase());

        $this->expectException(ChatUnavailable::class);
        $availability->assertAvailable();
    }

    public function testAvailableWithUsableGlobalRuleDocument(): void
    {
        $documents = new InMemoryDocumentRepository();
        $documents->setUsableGlobalRuleDocument(self::KB, true);
        $kbs = new InMemoryKnowledgeBaseRepository();
        $kbs->seedReady(self::KB, EnsureCommonRulesKnowledgeBaseService::SLUG, 'vs_rules');

        $availability = new RuleChatAvailability(
            new CommonRulesReadiness(new EnsureCommonRulesKnowledgeBaseService($kbs), $documents),
        );

        assertTrue($availability->isAvailable());
        assertSame(self::KB, $availability->assertAvailable()->id());
    }

    public function testGetDoesNotCreateThreadAndParticipantsAreIsolated(): void
    {
        $conversations = new InMemoryConversationRepository();
        $threads = new FindOrCreateThreadService($conversations, new MutableClock());

        assertNull($threads->find(self::KB, ChatParticipant::admin(1)));
        assertSame(0, $conversations->count());

        $adminThread = $threads->findOrCreate(self::KB, ChatParticipant::admin(1), 'Rules');
        $adminAgain = $threads->findOrCreate(self::KB, ChatParticipant::admin(1), 'Rules');
        $agentThread = $threads->findOrCreate(self::KB, ChatParticipant::agent(1), 'Rules');
        $otherAdmin = $threads->findOrCreate(self::KB, ChatParticipant::admin(2), 'Rules');

        assertSame($adminThread->id, $adminAgain->id);
        assertNotSame($adminThread->id, $agentThread->id);
        assertNotSame($adminThread->id, $otherAdmin->id);
        assertNotSame($agentThread->id, $otherAdmin->id);
    }

    public function testSeededDocumentDefaultsToUploadedPdfSourceType(): void
    {
        $documents = new InMemoryDocumentRepository();
        $documents->seed(5, self::KB, DocumentStatus::Ready);

        assertSame('uploaded_pdf', $documents->sourceTypeOfDocument(5));
    }
}
