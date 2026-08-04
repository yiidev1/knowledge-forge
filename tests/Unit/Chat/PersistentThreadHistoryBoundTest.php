<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Application\AskKnowledgeBaseService;
use App\Chat\Application\ChatParams;
use App\Chat\Application\Citation\CitationResolver;
use App\Chat\Application\FindOrCreateThreadService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Application\Grounding\GroundingVerifier;
use App\Chat\Application\History\RecentMessagesHistoryPolicy;
use App\Chat\Application\Instruction\ImmutableSecurityInstructions;
use App\Chat\Application\Instruction\InstructionBuilder;
use App\Chat\Application\Retrieval\ExhaustiveIntentDetector;
use App\Ai\Contract\Dto\IndexStatus;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\IndexedFileRole;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\KnowledgeBaseStatus;
use App\KnowledgeBase\Domain\VectorStoreStatus;
use App\Shared\Application\Correlation\CorrelationId;
use App\Shared\Infrastructure\Log\SafeLogContext;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Tests\Support\Fake\Ai\FakeChatCompletionProvider;
use App\Tests\Support\Fake\Chat\InMemoryConversationRepository;
use App\Tests\Support\Fake\Chat\InMemoryMessageRepository;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\Document\InMemoryIndexedFileRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryRuleRepository;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\NullLogger;

use function count;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertLessThanOrEqual;
use function PHPUnit\Framework\assertSame;

/**
 * A persistent thread may store hundreds of messages in the UI/DB, but OpenAI only receives the
 * bounded RecentMessagesHistoryPolicy window.
 */
final class PersistentThreadHistoryBoundTest extends Unit
{
    public function testLargeStoredThreadDoesNotInflateProviderHistory(): void
    {
        $conversations = new InMemoryConversationRepository();
        $messages = new InMemoryMessageRepository();
        $documents = new InMemoryDocumentRepository();
        $indexedFiles = new InMemoryIndexedFileRepository();
        $provider = new FakeChatCompletionProvider();
        $clock = new MutableClock();

        $documents->seed(5, 7, DocumentStatus::Ready);
        $fileId = $indexedFiles->createPending(5, IndexedFileRole::DerivedMarkdown, 'derived/x.md');
        $indexedFiles->setUploaded($fileId, 'file_1', IndexStatus::Completed);

        $params = new ChatParams(
            model: 'fake-chat',
            maxResults: 8,
            maxOutputTokens: 1200,
            forceFileSearch: true,
            requireCitations: true,
            minCitationScore: 0.0,
            fallbackMessage: 'fallback',
            maxQuestionLength: 2000,
        );

        $service = new AskKnowledgeBaseService(
            $conversations,
            new FindOrCreateThreadService($conversations, $clock),
            $messages,
            new InMemoryRuleRepository(),
            $documents,
            $provider,
            new InstructionBuilder(new ImmutableSecurityInstructions()),
            new RecentMessagesHistoryPolicy(4, 8000),
            new CitationResolver(
                $indexedFiles,
                $documents,
                new NullLogger(),
                new SafeLogContext(new SecretRedactor(), new CorrelationId('corr-test')),
            ),
            new GroundingVerifier($params),
            $clock,
            $params,
            new ExhaustiveIntentDetector(),
            new NullLogger(),
        );

        $kb = $this->knowledgeBase();
        $conversationId = null;
        for ($i = 0; $i < 60; $i++) {
            $conversationId = $service->startConversation($kb, 'Question number ' . $i . '?', ChatParticipant::admin(1));
        }

        assertSame(1, $conversations->count());
        assertCount(120, $messages->findByConversation((int) $conversationId));

        $historySent = $provider->lastRequest?->history ?? [];
        assertLessThanOrEqual(4, count($historySent));
    }

    public function testRepeatedAsksReuseSameConversation(): void
    {
        $conversations = new InMemoryConversationRepository();
        $messages = new InMemoryMessageRepository();
        $documents = new InMemoryDocumentRepository();
        $indexedFiles = new InMemoryIndexedFileRepository();
        $provider = new FakeChatCompletionProvider();
        $clock = new MutableClock();

        $documents->seed(5, 7, DocumentStatus::Ready);
        $fileId = $indexedFiles->createPending(5, IndexedFileRole::DerivedMarkdown, 'derived/x.md');
        $indexedFiles->setUploaded($fileId, 'file_1', IndexStatus::Completed);

        $params = new ChatParams(
            model: 'fake-chat',
            maxResults: 8,
            maxOutputTokens: 1200,
            forceFileSearch: true,
            requireCitations: true,
            minCitationScore: 0.0,
            fallbackMessage: 'fallback',
            maxQuestionLength: 2000,
        );

        $service = new AskKnowledgeBaseService(
            $conversations,
            new FindOrCreateThreadService($conversations, $clock),
            $messages,
            new InMemoryRuleRepository(),
            $documents,
            $provider,
            new InstructionBuilder(new ImmutableSecurityInstructions()),
            new RecentMessagesHistoryPolicy(10, 8000),
            new CitationResolver(
                $indexedFiles,
                $documents,
                new NullLogger(),
                new SafeLogContext(new SecretRedactor(), new CorrelationId('corr-test')),
            ),
            new GroundingVerifier($params),
            $clock,
            $params,
            new ExhaustiveIntentDetector(),
            new NullLogger(),
        );

        $kb = $this->knowledgeBase();
        $a = $service->startConversation($kb, 'First?', ChatParticipant::admin(1));
        $b = $service->startConversation($kb, 'Second?', ChatParticipant::admin(1));

        assertSame($a, $b);
        assertSame(1, $conversations->count());
        assertCount(4, $messages->findByConversation($a));
    }

    private function knowledgeBase(): KnowledgeBase
    {
        $now = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));

        return new KnowledgeBase(
            7,
            'HR Docs',
            'hr-docs',
            null,
            'Be terse.',
            'vs_1',
            VectorStoreStatus::Ready,
            null,
            KnowledgeBaseStatus::Active,
            $now,
            $now,
        );
    }
}
