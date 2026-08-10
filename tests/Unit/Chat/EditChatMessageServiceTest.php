<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Ai\Contract\Dto\AiErrorDetails;
use App\Ai\Contract\Dto\RawCitation;
use App\Ai\Contract\Exception\AiProcessingFailed;
use App\Chat\Application\AskKnowledgeBaseService;
use App\Chat\Application\ChatAvailabilityPolicy;
use App\Chat\Application\RuleChatAvailability;
use App\Rules\Application\CommonRulesReadiness;
use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\Chat\Application\ChatParams;
use App\Chat\Application\Citation\CitationResolver;
use App\Chat\Application\EditChatMessageService;
use App\Chat\Application\FindOrCreateThreadService;
use App\Chat\Application\Grounding\GroundingVerifier;
use App\Chat\Application\History\RecentMessagesHistoryPolicy;
use App\Chat\Application\Instruction\ImmutableSecurityInstructions;
use App\Chat\Application\Instruction\InstructionBuilder;
use App\Chat\Application\Retrieval\ExhaustiveIntentDetector;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\Exception\EditConflict;
use App\Chat\Domain\Exception\LatestQuestionUnanswered;
use App\Chat\Domain\Exception\NotLatestQuestion;
use App\Chat\Domain\Exception\QuestionInvalid;
use App\Chat\Domain\Exception\UnchangedContent;
use App\Chat\Domain\ParticipantType;
use App\Chat\Domain\ChatRetrievalScope;
use App\Ai\Contract\Dto\IndexStatus;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\IndexedFileRole;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\KnowledgeBaseStatus;
use App\KnowledgeBase\Domain\VectorStoreStatus;
use App\Shared\Application\Correlation\CorrelationId;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Infrastructure\Log\SafeLogContext;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Tests\Support\Fake\Ai\FakeChatCompletionProvider;
use App\Tests\Support\Fake\Chat\InMemoryConversationRepository;
use App\Tests\Support\Fake\Chat\InMemoryMessageRepository;
use App\Tests\Support\Fake\Chat\InMemoryMessageRevisionRepository;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\Document\InMemoryIndexedFileRepository;
use App\Tests\Support\Fake\ImmediateTransactionRunner;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseSourceRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryRuleRepository;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\NullLogger;

use function array_filter;
use function array_map;
use function array_values;
use function str_contains;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The editing rules, end to end minus HTTP and the database: only the latest question is editable at any
 * age and only by its owner; an edit saves a revision, supersedes the old answer, and regenerates a single
 * linked answer; a provider failure is recoverable; retries are idempotent; and a thread whose latest
 * question is unanswered refuses a new question.
 */
final class EditChatMessageServiceTest extends Unit
{
    private const KB = 7;
    private const KB_OTHER = 8;
    private const DOC = 5;
    private const DOC_OTHER = 6;
    private const FALLBACK = 'I could not find enough information in the selected knowledge base.';

    private InMemoryConversationRepository $conversations;
    private InMemoryMessageRepository $messages;
    private InMemoryMessageRevisionRepository $revisions;
    private InMemoryRuleRepository $rules;
    private InMemoryDocumentRepository $documents;
    private InMemoryIndexedFileRepository $indexedFiles;
    private InMemoryKnowledgeBaseRepository $knowledgeBases;
    private FakeChatCompletionProvider $provider;
    private MutableClock $clock;

    protected function _before(): void
    {
        $this->conversations = new InMemoryConversationRepository();
        $this->messages = new InMemoryMessageRepository();
        $this->revisions = new InMemoryMessageRevisionRepository();
        $this->rules = new InMemoryRuleRepository();
        $this->documents = new InMemoryDocumentRepository();
        $this->indexedFiles = new InMemoryIndexedFileRepository();
        $this->knowledgeBases = new InMemoryKnowledgeBaseRepository();
        $this->provider = new FakeChatCompletionProvider();
        $this->clock = new MutableClock();

        // A ready document (and its indexed-file row) under each knowledge base used here.
        $this->documents->seed(self::DOC, self::KB, DocumentStatus::Ready);
        $this->documents->seed(self::DOC_OTHER, self::KB_OTHER, DocumentStatus::Ready);
        $this->documents->setUsableQualifyingDocument(self::KB, true);
        $this->documents->setUsableQualifyingDocument(self::KB_OTHER, true);
        $fileId = $this->indexedFiles->createPending(self::DOC, IndexedFileRole::DerivedMarkdown, 'derived/x.md');
        $this->indexedFiles->setUploaded($fileId, 'file_1', IndexStatus::Completed);
    }

    public function testAdminEditsLatestQuestionRegeneratesAndLinksAnswer(): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');
        $userId = $this->latestUserId($conversationId);

        $this->provider->setResult(FakeChatCompletionProvider::grounded('Regenerated answer.', [new RawCitation('file_1', 'doc.md', 0)]));
        $outcome = $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId, $userId, 'Edited question?', 0);

        assertTrue($outcome->answerRegenerated);

        $target = $this->messages->findByIdInConversation($userId, $conversationId);
        assertNotNull($target);
        assertSame('Edited question?', $target->content);
        assertSame(1, $target->editCount);
        assertTrue($target->isEdited());
        assertNotNull($target->editedAt);

        // The prior text is preserved as a revision attributed to the editor.
        $revisions = $this->revisions->findByMessage($userId);
        assertCount(1, $revisions);
        assertSame('First question?', $revisions[0]->content);
        assertSame(1, $revisions[0]->revisionNumber);
        assertSame(ParticipantType::Admin, $revisions[0]->editedByType);
        assertSame(1, $revisions[0]->editedById);

        // Live thread: the edited question and exactly one fresh answer linked to it.
        $live = $this->messages->findByConversation($conversationId);
        assertCount(2, $live);
        assertSame('Edited question?', $live[0]->content);
        assertSame('Regenerated answer.', $live[1]->content);
        assertSame($userId, $live[1]->replyToMessageId);

        // The old answer is superseded, not deleted.
        $all = $this->messages->findAllByConversationIncludingSuperseded($conversationId);
        assertCount(3, $all);
        $superseded = array_values(array_filter($all, static fn($m) => $m->isSuperseded()));
        assertCount(1, $superseded);
        assertSame('An answer.', $superseded[0]->content);
    }

    public function testAgentEditsLatestQuestion(): void
    {
        $participant = ChatParticipant::agent(1);
        $conversationId = $this->seed($participant, 'Agent question?');
        $userId = $this->latestUserId($conversationId);

        $outcome = $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId, $userId, 'Agent edited?', 0);

        assertTrue($outcome->answerRegenerated);
        assertSame('Agent edited?', $this->messages->findByIdInConversation($userId, $conversationId)?->content);
        $revisions = $this->revisions->findByMessage($userId);
        assertCount(1, $revisions);
        assertSame(ParticipantType::Agent, $revisions[0]->editedByType);
        assertSame(1, $this->activeAnswerCount($conversationId, $userId));
    }

    /**
     * @dataProvider oldLatestAges
     */
    public function testLatestQuestionRemainsEditableRegardlessOfAge(string $advance): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');
        $userId = $this->latestUserId($conversationId);

        $this->clock->advance($advance);

        $outcome = $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId, $userId, 'Edited later?', 0);

        assertTrue($outcome->answerRegenerated);
        assertSame('Edited later?', $this->messages->findByIdInConversation($userId, $conversationId)?->content);
        assertCount(1, $this->revisions->findByMessage($userId));
        assertSame(1, $this->activeAnswerCount($conversationId, $userId));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public function oldLatestAges(): iterable
    {
        yield '5 minutes' => ['+5 minutes'];
        yield '20 minutes' => ['+20 minutes'];
        yield '21 minutes (former cutoff)' => ['+21 minutes'];
        yield '3 days' => ['+3 days'];
        yield '6 months' => ['+6 months'];
    }

    public function testAssistantMessageIsNotEditable(): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');
        $assistantId = $this->messages->findByConversation($conversationId)[1]->id;

        try {
            $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId, $assistantId, 'Editing the answer?', 0);
            $this->fail('Expected NotFoundException.');
        } catch (NotFoundException $e) {
            assertSame('message_not_editable', $e->errorCode());
        }
    }

    public function testNonLatestQuestionIsRejectedEvenWhenRecent(): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');
        $firstUserId = $this->latestUserId($conversationId);
        $this->askService()->ask($this->knowledgeBase(), $conversationId, 'Second question?');

        // Advance only one minute — age must not make an older question editable.
        $this->clock->advance('+1 minute');

        $this->expectException(NotLatestQuestion::class);
        $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId, $firstUserId, 'Editing the older one?', 0);
    }

    public function testEmptyContentIsRejected(): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');
        $userId = $this->latestUserId($conversationId);

        $this->expectException(QuestionInvalid::class);
        $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId, $userId, '   ', 0);
    }

    public function testUnchangedContentIsRejected(): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');
        $userId = $this->latestUserId($conversationId);

        $this->expectException(UnchangedContent::class);
        $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId, $userId, 'First question?', 0);
    }

    public function testAnotherAdminCannotEdit(): void
    {
        $owner = ChatParticipant::admin(1);
        $conversationId = $this->seed($owner, 'First question?');
        $userId = $this->latestUserId($conversationId);

        $this->expectException(NotFoundException::class);
        $this->editService()->edit($this->knowledgeBase(), ChatParticipant::admin(2), $conversationId, $userId, 'Hijack?', 0);
    }

    public function testAnotherAgentCannotEdit(): void
    {
        $owner = ChatParticipant::agent(1);
        $conversationId = $this->seed($owner, 'Agent question?');
        $userId = $this->latestUserId($conversationId);

        $this->expectException(NotFoundException::class);
        $this->editService()->edit($this->knowledgeBase(), ChatParticipant::agent(2), $conversationId, $userId, 'Hijack?', 0);
    }

    public function testAgentWithSameNumericIdCannotEditAdminThread(): void
    {
        $owner = ChatParticipant::admin(1);
        $conversationId = $this->seed($owner, 'First question?');
        $userId = $this->latestUserId($conversationId);

        // Admin id 1 and agent id 1 are different owners; the typed participant must not collide.
        $this->expectException(NotFoundException::class);
        $this->editService()->edit($this->knowledgeBase(), ChatParticipant::agent(1), $conversationId, $userId, 'Cross realm?', 0);
    }

    public function testForgedConversationIdIsNotFound(): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');
        $userId = $this->latestUserId($conversationId);

        $this->expectException(NotFoundException::class);
        $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId + 999, $userId, 'Forged?', 0);
    }

    public function testForgedMessageIdIsNotFound(): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');

        $this->expectException(NotFoundException::class);
        $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId, 99999, 'Forged?', 0);
    }

    public function testConversationFromAnotherKnowledgeBaseIsNotFound(): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');
        $userId = $this->latestUserId($conversationId);

        // Same owner, same ids, but resolved under a different (also ready) knowledge base → 404.
        $this->expectException(NotFoundException::class);
        $this->editService()->edit($this->knowledgeBase(self::KB_OTHER), $participant, $conversationId, $userId, 'Wrong base?', 0);
    }

    public function testRegenerationHistoryExcludesTheSupersededAnswer(): void
    {
        $participant = ChatParticipant::admin(1);
        $this->provider->setResult(FakeChatCompletionProvider::grounded('Answer one.', [new RawCitation('file_1', 'doc.md', 0)]));
        $conversationId = $this->seed($participant, 'First question?');

        $this->provider->setResult(FakeChatCompletionProvider::grounded('Answer two.', [new RawCitation('file_1', 'doc.md', 0)]));
        $this->askService()->ask($this->knowledgeBase(), $conversationId, 'Second question?');
        $secondUserId = $this->latestUserId($conversationId);

        $this->provider->setResult(FakeChatCompletionProvider::grounded('Answer two prime.', [new RawCitation('file_1', 'doc.md', 0)]));
        $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId, $secondUserId, 'Second edited?', 0);

        // The regeneration saw only the first, non-superseded turn — never the replaced "Answer two.".
        $history = array_map(static fn($m) => $m->text, $this->provider->lastRequest?->history ?? []);
        assertCount(2, $history);
        assertSame(['First question?', 'Answer one.'], $history);
        assertNotContains('Answer two.', $history);
        assertSame('Second edited?', $this->provider->lastRequest?->question);
    }

    public function testStoreChatEditUsesStoreKnowledgeInstructions(): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');
        $userId = $this->latestUserId($conversationId);

        $this->editService()->edit(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $userId,
            'Edited store question?',
            0,
            ChatRetrievalScope::StoreKnowledge,
        );

        $instructions = (string) ($this->provider->lastRequest?->instructions ?? '');
        assertFalse(str_contains($instructions, '[rule chat]'));
        assertTrue(str_contains($instructions, '[reminder]'));
    }

    public function testRuleChatEditUsesRuleOnlyInstructions(): void
    {
        $this->knowledgeBases->seedReady(self::KB, EnsureCommonRulesKnowledgeBaseService::SLUG, 'vs_1');
        $this->documents->setUsableGlobalRuleDocument(self::KB, true);
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First rule question?');
        $userId = $this->latestUserId($conversationId);

        $this->editService()->edit(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $userId,
            'Edited rule question?',
            0,
            ChatRetrievalScope::RuleOnly,
        );

        $instructions = (string) ($this->provider->lastRequest?->instructions ?? '');
        assertTrue(str_contains($instructions, '[rule chat]'));
        assertSame(1, $this->activeAnswerCount($conversationId, $userId));
    }

    public function testRegenerationFailureLeavesRecoverableRetryState(): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');
        $userId = $this->latestUserId($conversationId);

        $this->provider->willThrow(new AiProcessingFailed(AiErrorDetails::of('processing_failed', 'boom', transient: true)));
        $outcome = $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId, $userId, 'Edited but doomed?', 0);

        assertFalse($outcome->answerRegenerated);
        // The edit still committed: question saved, revision kept, old answer superseded, no active answer.
        assertSame('Edited but doomed?', $this->messages->findByIdInConversation($userId, $conversationId)?->content);
        assertCount(1, $this->revisions->findByMessage($userId));
        assertSame(0, $this->activeAnswerCount($conversationId, $userId));
        assertTrue($this->messages->hasUnansweredLatestUserMessage($conversationId));
    }

    public function testUnansweredLatestQuestionBlocksANewQuestion(): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');
        $userId = $this->latestUserId($conversationId);

        $this->provider->willThrow(new AiProcessingFailed(AiErrorDetails::of('processing_failed', 'boom', transient: true)));
        $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId, $userId, 'Edited but doomed?', 0);

        $this->provider->stopThrowing();

        $this->expectException(LatestQuestionUnanswered::class);
        $this->askService()->ask($this->knowledgeBase(), $conversationId, 'A brand new question?');
    }

    public function testRetryRecoversAndIsIdempotent(): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');
        $userId = $this->latestUserId($conversationId);

        $this->provider->willThrow(new AiProcessingFailed(AiErrorDetails::of('processing_failed', 'boom', transient: true)));
        $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId, $userId, 'Edited but doomed?', 0);

        $this->provider->stopThrowing();
        $this->provider->setResult(FakeChatCompletionProvider::grounded('Recovered answer.', [new RawCitation('file_1', 'doc.md', 0)]));

        $first = $this->editService()->regenerateAnswer($this->knowledgeBase(), $participant, $conversationId, $userId);
        $second = $this->editService()->regenerateAnswer($this->knowledgeBase(), $participant, $conversationId, $userId);

        assertTrue($first->answerRegenerated);
        assertTrue($second->answerRegenerated);
        // Two retries must not leave two active answers.
        assertSame(1, $this->activeAnswerCount($conversationId, $userId));
        assertFalse($this->messages->hasUnansweredLatestUserMessage($conversationId));
    }

    public function testStaleEditCountConflicts(): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');
        $userId = $this->latestUserId($conversationId);

        try {
            // The client believed the question had already been edited once; the DB says zero.
            $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId, $userId, 'Concurrent edit?', 99);
            $this->fail('Expected EditConflict.');
        } catch (EditConflict) {
            assertSame('First question?', $this->messages->findByIdInConversation($userId, $conversationId)?->content);
            assertCount(0, $this->revisions->findByMessage($userId));
        }
    }

    public function testAuditAccessorsStillReturnSupersededAndRevisions(): void
    {
        $participant = ChatParticipant::admin(1);
        $conversationId = $this->seed($participant, 'First question?');
        $userId = $this->latestUserId($conversationId);

        $this->editService()->edit($this->knowledgeBase(), $participant, $conversationId, $userId, 'Edited question?', 0);

        // Live view hides the old answer…
        $live = $this->messages->findByConversation($conversationId);
        assertFalse((bool) array_filter($live, static fn($m) => $m->isSuperseded()));
        // …but the audit accessors still surface it and the revision.
        $all = $this->messages->findAllByConversationIncludingSuperseded($conversationId);
        assertCount(1, array_values(array_filter($all, static fn($m) => $m->isSuperseded())));
        assertCount(1, $this->revisions->findByMessage($userId));
    }

    private function seed(ChatParticipant $participant, string $question): int
    {
        return $this->askService()->startConversation($this->knowledgeBase(), $question, $participant);
    }

    private function latestUserId(int $conversationId): int
    {
        $id = $this->messages->findLatestUserMessageId($conversationId);
        assertNotNull($id);

        return $id;
    }

    private function activeAnswerCount(int $conversationId, int $userMessageId): int
    {
        $n = 0;
        foreach ($this->messages->findByConversation($conversationId) as $message) {
            if ($message->isAssistant() && $message->replyToMessageId === $userMessageId) {
                ++$n;
            }
        }

        return $n;
    }

    private function askService(): AskKnowledgeBaseService
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
            new NullLogger(),
            $this->documents,
        );
    }

    private function editService(): EditChatMessageService
    {
        return new EditChatMessageService(
            $this->conversations,
            $this->messages,
            $this->revisions,
            $this->askService(),
            new ImmediateTransactionRunner(),
            $this->clock,
        );
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

    private function knowledgeBase(int $id = self::KB, string $vectorStoreId = 'vs_1'): KnowledgeBase
    {
        $now = new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC'));

        return new KnowledgeBase(
            $id,
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
