<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Application\AskKnowledgeBaseService;
use App\Chat\Application\ChatAvailabilityPolicy;
use App\Chat\Application\RuleChatAvailability;
use App\Rules\Application\CommonRulesReadiness;
use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\Chat\Application\ChatParams;
use App\Chat\Application\Citation\CitationResolver;
use App\Chat\Application\FindOrCreateThreadService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Application\Grounding\GroundingVerifier;
use App\Chat\Application\History\RecentMessagesHistoryPolicy;
use App\Chat\Application\Instruction\ImmutableSecurityInstructions;
use App\Chat\Application\Instruction\InstructionBuilder;
use App\Chat\Application\Retrieval\ExhaustiveIntentDetector;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Domain\Exception\QuestionInvalid;
use App\Chat\Domain\MessageRole;
use App\Ai\Contract\Dto\IndexStatus;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\IndexedFileRole;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\KnowledgeBaseStatus;
use App\KnowledgeBase\Domain\VectorStoreStatus;
use App\Shared\Application\Correlation\CorrelationId;
use App\Shared\Infrastructure\Log\SafeLogContext;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Tests\Support\Fake\Ai\CapturingLogger;
use App\Tests\Support\Fake\Ai\FakeChatCompletionProvider;
use App\Tests\Support\Fake\Chat\InMemoryConversationRepository;
use App\Tests\Support\Fake\Chat\InMemoryMessageRepository;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\Document\InMemoryIndexedFileRepository;
use App\Ai\Contract\Dto\RawCitation;
use App\Chat\Domain\AnswerSource;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseSourceRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryRuleRepository;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\NullLogger;

use function str_repeat;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * End to end (minus HTTP and the database): a question becomes a persisted, grounded answer; an
 * unretrieved answer becomes the persisted fallback; invalid questions and unready bases are refused;
 * and the instructions carry the immutable block and the KB rules in order.
 */
final class AskKnowledgeBaseServiceTest extends Unit
{
    private const KB = 7;
    private const DOC = 5;
    private const FALLBACK = 'I could not find enough information in the selected knowledge base.';

    private InMemoryConversationRepository $conversations;
    private InMemoryMessageRepository $messages;
    private InMemoryRuleRepository $rules;
    private InMemoryDocumentRepository $documents;
    private InMemoryIndexedFileRepository $indexedFiles;
    private InMemoryKnowledgeBaseRepository $knowledgeBases;
    private FakeChatCompletionProvider $provider;
    private MutableClock $clock;
    private CapturingLogger $logger;

    protected function _before(): void
    {
        $this->logger = new CapturingLogger();
        $this->conversations = new InMemoryConversationRepository();
        $this->messages = new InMemoryMessageRepository();
        $this->rules = new InMemoryRuleRepository();
        $this->documents = new InMemoryDocumentRepository();
        $this->indexedFiles = new InMemoryIndexedFileRepository();
        $this->knowledgeBases = new InMemoryKnowledgeBaseRepository();
        $this->provider = new FakeChatCompletionProvider();
        $this->clock = new MutableClock();

        // One indexed, ready document, and the index-file row a citation resolves through.
        $this->documents->seed(self::DOC, self::KB, DocumentStatus::Ready);
        $this->documents->setUsableQualifyingDocument(self::KB, true);
        $fileId = $this->indexedFiles->createPending(self::DOC, IndexedFileRole::DerivedMarkdown, 'derived/x.md');
        $this->indexedFiles->setUploaded($fileId, 'file_1', IndexStatus::Completed);

        $this->rules->create(self::KB, 'A', 'Answer only from the documents', 10, true);
        $this->rules->create(self::KB, 'B', 'Use simple English', 20, true);
    }

    public function testStartConversationPersistsGroundedAnswerWithCitations(): void
    {
        $conversationId = $this->service()->startConversation($this->knowledgeBase(), 'What is the policy?', ChatParticipant::admin(1));

        $messages = $this->messages->findByConversation($conversationId);
        assertCount(2, $messages);
        assertSame(MessageRole::User, $messages[0]->role);
        assertSame('What is the policy?', $messages[0]->content);
        assertSame(MessageRole::Assistant, $messages[1]->role);
        assertSame('An answer.', $messages[1]->content);
        assertTrue($messages[1]->isGrounded);
        assertCount(1, $messages[1]->citations);
        assertSame('doc.pdf', $messages[1]->citations[0]->filename);
    }

    public function testInstructionsCarryImmutableBlockAndRulesInOrder(): void
    {
        $this->service()->startConversation($this->knowledgeBase(), 'Question?', ChatParticipant::admin(1));

        $instructions = (string) $this->provider->lastRequest?->instructions;
        assertStringContainsString('[IMMUTABLE', $instructions);
        assertStringContainsString('1. Answer only from the documents', $instructions);
        assertStringContainsString('2. Use simple English', $instructions);
    }

    public function testUnretrievedAnswerIsStoredAsFallback(): void
    {
        $this->provider->setResult(FakeChatCompletionProvider::noRetrieval('I definitely know this.'));

        $conversationId = $this->service()->startConversation($this->knowledgeBase(), 'Off-topic?', ChatParticipant::admin(1));

        $assistant = $this->messages->findByConversation($conversationId)[1];
        assertSame(self::FALLBACK, $assistant->content);
        assertSame(false, $assistant->isGrounded);
        assertSame('not_called', $assistant->retrievalStatus);
    }

    public function testFollowUpSendsPriorTurnsAsHistory(): void
    {
        $service = $this->service();
        $conversationId = $service->startConversation($this->knowledgeBase(), 'First?', ChatParticipant::admin(1));

        $service->ask($this->knowledgeBase(), $conversationId, 'Second?');

        // History for the second call is the first user+assistant pair.
        assertCount(2, (array) $this->provider->lastRequest?->history);
    }

    /**
     * The regression this whole change exists for: a question asking for every match must search wider
     * and carry the exhaustive directive, while a narrow question is left exactly as it was.
     */
    public function testExhaustiveQuestionWidensRetrievalAndAddsTheDirective(): void
    {
        $this->service()->startConversation(
            $this->knowledgeBase(),
            'List the exact titles of all records whose title contains Moon Temple.',
            ChatParticipant::admin(1),
        );

        assertSame(20, $this->provider->lastRequest?->maxResults);
        assertStringContainsString('[exhaustive answer]', (string) $this->provider->lastRequest?->instructions);
    }

    public function testNarrowQuestionKeepsTheDefaultRetrievalWidth(): void
    {
        $this->service()->startConversation(
            $this->knowledgeBase(),
            'Return one record whose title contains Moon Temple.',
            ChatParticipant::admin(1),
        );

        assertSame(8, $this->provider->lastRequest?->maxResults);
        assertStringNotContainsString('[exhaustive answer]', (string) $this->provider->lastRequest?->instructions);
    }

    public function testReasoningEffortIsPassedToTheProvider(): void
    {
        $this->service()->startConversation($this->knowledgeBase(), 'Question?', ChatParticipant::admin(1));

        assertSame('low', $this->provider->lastRequest?->reasoningEffort);
    }

    /**
     * Every answer leaves a diagnosable trace. Without it the outcome is unrecoverable: the stored text
     * is identical whether the verifier rejected the answer or the model refused on its own, and the
     * response is not retrievable from the provider afterwards.
     */
    public function testAnswerIsLoggedWithSafeRetrievalAndGroundingMetadata(): void
    {
        $this->service()->startConversation($this->knowledgeBase(), 'What is the policy?', ChatParticipant::admin(1));

        $logged = $this->logger->everything();

        assertStringContainsString('chat answer', $logged);
        assertStringContainsString('"retrieval_status":"completed"', $logged);
        assertStringContainsString('"resolved_citation_count":1', $logged);
        assertStringContainsString('"is_grounded":true', $logged);
        assertStringContainsString('"reasoning_effort":"low"', $logged);
    }

    /**
     * The diagnostics must never become a leak. The question text and the answer are the obvious risks;
     * the vector-store id is the subtle one, which is why only a short suffix is recorded — enough to
     * correlate two log lines, not enough to be an identifier on its own.
     */
    public function testDiagnosticsDoNotLeakQuestionAnswerOrFullVectorStoreId(): void
    {
        $vectorStoreId = 'vs_68a1f4c2d3b94e0aa7c50768be';

        $this->service()->startConversation(
            $this->knowledgeBase($vectorStoreId),
            'What is the secret handshake?',
            ChatParticipant::admin(1),
        );

        $logged = $this->logger->everything();

        assertStringNotContainsString('secret handshake', $logged);
        assertStringNotContainsString('An answer.', $logged);
        assertStringNotContainsString($vectorStoreId, $logged);
        assertStringContainsString('"vector_store_suffix":"0768be"', $logged);
    }

    public function testQuestionTooLongIsRejectedAndNothingIsPersisted(): void
    {
        $long = str_repeat('a', 2001);

        try {
            $this->service()->startConversation($this->knowledgeBase(), $long, ChatParticipant::admin(1));
            $this->fail('Expected QuestionInvalid.');
        } catch (QuestionInvalid) {
            assertSame(0, $this->conversations->count());
        }
    }

    public function testChatUnavailableWhenNoReadyDocuments(): void
    {
        $this->documents = new InMemoryDocumentRepository(); // no ready documents

        $this->expectException(ChatUnavailable::class);
        $this->service()->startConversation($this->knowledgeBase(), 'Question?', ChatParticipant::admin(1));
    }

    private function service(): AskKnowledgeBaseService
    {
        $params = $this->params();

        return new AskKnowledgeBaseService(
            $this->conversations,
            new FindOrCreateThreadService($this->conversations, $this->clock),
            $this->messages,
            $this->rules,
            new ChatAvailabilityPolicy($this->documents, new InMemoryKnowledgeBaseSourceRepository()),
            new RuleChatAvailability(new CommonRulesReadiness(new EnsureCommonRulesKnowledgeBaseService($this->knowledgeBases), $this->documents)),
            $this->provider,
            new InstructionBuilder(new ImmutableSecurityInstructions()),
            new RecentMessagesHistoryPolicy(10, 8000),
            new CitationResolver(
                $this->indexedFiles,
                $this->documents,
                new NullLogger(),
                new SafeLogContext(new SecretRedactor(), new CorrelationId('corr-test')),
            ),
            new GroundingVerifier($params),
            $this->clock,
            $params,
            new ExhaustiveIntentDetector(),
            $this->logger,
            $this->documents,
        );
    }

    public function testStoreChatSearchesOnlyItsOwnVectorStoreForAGroundedAnswer(): void
    {
        $conversationId = $this->service()->startConversation($this->knowledgeBase(), 'Q?', ChatParticipant::admin(1));

        $assistant = $this->messages->findByConversation($conversationId)[1];
        assertSame('An answer.', $assistant->content);
        assertSame(AnswerSource::StoreKnowledge, $assistant->answerSource);
        assertSame(['vs_1'], $this->provider->askedVectorStoreIds, 'store chat searches only its own vector store');
    }

    public function testStoreRuleCitationIsRejectedByStoreChatScope(): void
    {
        // Phase 2: Store Chat must not ground on Order58 rule projections, even if File Search returns them.
        $this->documents->setSourceType(self::DOC, 'order58_rule_store');

        $conversationId = $this->service()->startConversation($this->knowledgeBase(), 'Q?', ChatParticipant::admin(1));

        $assistant = $this->messages->findByConversation($conversationId)[1];
        assertSame(self::FALLBACK, $assistant->content);
        assertSame(AnswerSource::Fallback, $assistant->answerSource);
        assertTrue(!$assistant->isGrounded);
    }

    public function testStoreChatAcceptsStoreProfileCitationWhenRetrieved(): void
    {
        // Availability qualification excludes the profile, but citation eligibility does not — profile may still ground.
        $this->documents->setSourceType(self::DOC, 'order58_store_profile');
        $this->documents->setUsableQualifyingDocument(self::KB, true); // seeded separately as readiness

        $conversationId = $this->service()->startConversation($this->knowledgeBase(), 'Q?', ChatParticipant::admin(1));

        $assistant = $this->messages->findByConversation($conversationId)[1];
        assertTrue($assistant->isGrounded);
        assertSame(AnswerSource::StoreKnowledge, $assistant->answerSource);
    }

    /**
     * The answer-shape rules must reach the model, not merely exist in a builder. Asserted on the request
     * the provider actually received, for both retrieval scopes — Store Chat and Rule Chat use different
     * builder methods, so each needs its own proof.
     *
     * Both admin and agent surfaces call these same service methods with only the scope differing, so a
     * pass here covers all four chat surfaces.
     */
    public function testStoreChatSendsTheAnswerShapeRulesToTheProvider(): void
    {
        $this->service()->startConversation($this->knowledgeBase(), 'Question?', ChatParticipant::admin(1));

        $instructions = (string) $this->provider->lastRequest?->instructions;
        assertStringContainsString('Answer the question, then stop.', $instructions);
        assertStringContainsString('Use quotation marks only for wording that appears verbatim', $instructions);
        assertStringContainsString('ordinary answers continue to summarise retrieved content normally', $instructions);
    }

    public function testRuleChatSendsTheAnswerShapeRulesToTheProvider(): void
    {
        $this->documents->setSourceType(self::DOC, 'order58_rule_global');
        $this->documents->setUsableGlobalRuleDocument(self::KB, true);
        $this->seedRulesKnowledgeBase();

        $this->service()->startConversation(
            $this->knowledgeBase('vs_rules'),
            'What is the rule?',
            ChatParticipant::admin(1),
            \App\Chat\Domain\ChatRetrievalScope::RuleOnly,
        );

        $instructions = (string) $this->provider->lastRequest?->instructions;
        assertStringContainsString('Answer the question, then stop.', $instructions);
        assertStringContainsString('Use quotation marks only for wording that appears verbatim', $instructions);
        // Rule Chat keeps its own scope directive — the addition did not displace it.
        assertStringContainsString('[rule chat]', $instructions);
    }

    public function testRuleChatGroundsOnGlobalRuleCitation(): void
    {
        $this->documents->setSourceType(self::DOC, 'order58_rule_global');
        $this->documents->setUsableGlobalRuleDocument(self::KB, true);
        $this->seedRulesKnowledgeBase();

        $conversationId = $this->service()->startConversation(
            $this->knowledgeBase('vs_rules'),
            'What is the rule?',
            ChatParticipant::admin(1),
            \App\Chat\Domain\ChatRetrievalScope::RuleOnly,
        );

        $assistant = $this->messages->findByConversation($conversationId)[1];
        assertTrue($assistant->isGrounded);
        assertSame(AnswerSource::GlobalRule, $assistant->answerSource);
        assertSame(['vs_rules'], $this->provider->askedVectorStoreIds);
        $instructions = (string) $this->provider->lastRequest?->instructions;
        assertStringContainsString('[rule chat]', $instructions);
        assertStringNotContainsString('[knowledge base rules]', $instructions);
    }

    public function testRuleChatRejectsNonRuleCitation(): void
    {
        $this->documents->setSourceType(self::DOC, 'order58_knowledge');
        $this->documents->setUsableGlobalRuleDocument(self::KB, true);
        $this->seedRulesKnowledgeBase();

        $conversationId = $this->service()->startConversation(
            $this->knowledgeBase('vs_rules'),
            'Q?',
            ChatParticipant::admin(1),
            \App\Chat\Domain\ChatRetrievalScope::RuleOnly,
        );

        $assistant = $this->messages->findByConversation($conversationId)[1];
        assertSame(self::FALLBACK, $assistant->content);
        assertSame(AnswerSource::Fallback, $assistant->answerSource);
    }

    public function testRuleChatUnavailableWhenNoIndexedRules(): void
    {
        $this->expectException(ChatUnavailable::class);
        $this->service()->startConversation(
            $this->knowledgeBase('vs_rules'),
            'Q?',
            ChatParticipant::admin(1),
            \App\Chat\Domain\ChatRetrievalScope::RuleOnly,
        );
    }

    private function seedRulesKnowledgeBase(string $vectorStoreId = 'vs_rules'): void
    {
        $this->knowledgeBases->seedReady(
            self::KB,
            EnsureCommonRulesKnowledgeBaseService::SLUG,
            $vectorStoreId,
        );
    }

    /**
     * The Phase-1 retrieval boundary: Store Chat must never consult the hidden global/common rules base. Even
     * with a groundable global-rules answer configured on another vector store, an ungrounded store result
     * becomes the fallback and the global vector store is never searched — rule content is served only by the
     * dedicated Rule Chat now.
     */
    public function testStoreChatNeverConsultsTheGlobalRulesBaseWhenUngrounded(): void
    {
        $this->provider->setResultForStore('vs_1', FakeChatCompletionProvider::noRetrieval('store cannot answer'));
        $this->provider->setResultForStore('vs_common', FakeChatCompletionProvider::grounded('Global answer.', [new RawCitation('file_c', 'c.md', 0)]));

        $conversationId = $this->service()->startConversation($this->knowledgeBase(), 'Q?', ChatParticipant::admin(1));

        $messages = $this->messages->findByConversation($conversationId);
        assertCount(2, $messages, 'exactly one assistant answer is saved');
        assertSame(self::FALLBACK, $messages[1]->content, 'store chat must not return a global-rules answer');
        assertSame(AnswerSource::Fallback, $messages[1]->answerSource);
        assertSame(['vs_1'], $this->provider->askedVectorStoreIds, 'the global rules base is never searched by store chat');
    }

    private function params(): ChatParams
    {
        return new ChatParams(
            model: 'fake-chat',
            maxResults: 8,
            maxOutputTokens: 1200,
            forceFileSearch: true,
            requireCitations: true,
            minCitationScore: 0.0,
            fallbackMessage: self::FALLBACK,
            maxQuestionLength: 2000,
            reasoningEffort: 'low',
            exhaustiveMaxResults: 20,
        );
    }

    private function knowledgeBase(string $vectorStoreId = 'vs_1'): KnowledgeBase
    {
        $now = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));

        return new KnowledgeBase(
            self::KB,
            'HR Docs',
            'hr-docs',
            null,
            'Be terse.',
            $vectorStoreId,
            VectorStoreStatus::Ready,
            null,
            KnowledgeBaseStatus::Active,
            $now,
            $now,
        );
    }
}
