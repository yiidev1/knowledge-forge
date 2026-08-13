<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Application\ChatKnowledgeSourcesService;
use App\Chat\Application\ShowChatSourceService;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ChatRetrievalScope;
use App\Chat\Domain\MessageRole;
use App\Chat\Domain\NewMessage;
use App\Chat\Domain\ResolvedCitation;
use App\Document\Application\ServeCanonicalDocumentService;
use App\Document\Domain\CanonicalDocument;
use App\Document\Domain\DocumentKind;
use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\DocumentStatus;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\KnowledgeBaseStatus;
use App\KnowledgeBase\Domain\VectorStoreStatus;
use App\Order58\Application\Order58DisplayParams;
use App\Shared\Domain\Exception\NotFoundException;
use App\Tests\Support\Fake\Chat\InMemoryConversationRepository;
use App\Tests\Support\Fake\Chat\InMemoryMessageRepository;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\Document\InMemoryDocumentStorage;
use App\Tests\Support\Fake\Document\InMemoryTextDocumentRepository;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use HttpSoft\Message\ResponseFactory;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Who may read a cited source, and who may not.
 *
 * Almost every test here is a refusal, because that is where the value is: the document id arrives from the
 * browser and the service has to treat it as hostile. The one property under test throughout is that a
 * reader can only see a document THIS answer cited, in THEIR OWN thread, within the surface's own scope —
 * and that every other case is indistinguishable from "not found".
 */
final class ShowChatSourceServiceTest extends Unit
{
    private const KB = 7;
    private const KB_OTHER = 8;

    /** Cited by the seeded answer. */
    private const CITED_DOC = 100;

    /** Real, readable, in the same knowledge base — but not cited by the seeded answer. */
    private const UNCITED_DOC = 101;

    /** A rule projection: visible to Rule Chat, invisible to Store Chat. */
    private const RULE_DOC = 102;

    /** An Order58 store profile: hidden unless the operator switch is on. */
    private const PROFILE_DOC = 103;

    private InMemoryConversationRepository $conversations;
    private InMemoryMessageRepository $messages;
    private InMemoryTextDocumentRepository $textDocuments;
    private InMemoryDocumentRepository $documents;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->conversations = new InMemoryConversationRepository();
        $this->messages = new InMemoryMessageRepository();
        $this->textDocuments = new InMemoryTextDocumentRepository();
        $this->documents = new InMemoryDocumentRepository();
        $this->now = new DateTimeImmutable('2026-05-01 10:00:00', new DateTimeZone('UTC'));

        $this->seedDocument(self::CITED_DOC, DocumentSourceType::ManualText, 'Delivery policy', 'Delivery is free over $30.');
        $this->seedDocument(self::UNCITED_DOC, DocumentSourceType::ManualText, 'Refund policy', 'Refunds within 14 days.');
        $this->seedDocument(self::RULE_DOC, DocumentSourceType::Order58RuleGlobal, 'Shrimp rule', 'Add shrimp for $2.');
        $this->seedDocument(self::PROFILE_DOC, DocumentSourceType::Order58StoreProfile, 'Store profile', 'Open 9–5.');
        $this->documents->setUsableDocumentIds(self::CITED_DOC, self::UNCITED_DOC, self::RULE_DOC, self::PROFILE_DOC);
    }

    // ---------------------------------------------------------------- the source is shown

    public function testAReaderSeesASourceTheirOwnAnswerCited(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $item = $this->service()->detailFor(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $answerId,
            self::CITED_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );

        assertSame('Delivery policy', $item->title);
        assertSame('Manual text', $item->typeLabel());
        assertSame('Delivery is free over $30.', $item->preview);
    }

    public function testAnAgentSeesASourceTheirOwnAnswerCited(): void
    {
        $participant = ChatParticipant::agent(42);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $item = $this->service()->detailFor(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $answerId,
            self::CITED_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );

        assertSame('Delivery policy', $item->title);
    }

    /**
     * Rule Chat reads the rule corpus with the same service; only the scope differs.
     */
    public function testRuleChatSeesARuleItsAnswerCited(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant, self::RULE_DOC);

        $item = $this->service()->detailFor(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $answerId,
            self::RULE_DOC,
            ChatRetrievalScope::RuleOnly,
        );

        assertSame('Shrimp rule', $item->title);
        assertSame('Global rule', $item->typeLabel());
    }

    // ---------------------------------------------------------------- the citation gate

    /**
     * THE central test. A real, readable document in the very same knowledge base is still refused, because
     * this answer did not cite it. Editing the id in the URL therefore buys nothing.
     */
    public function testADocumentThisAnswerDidNotCiteIsNotFound(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->expectException(NotFoundException::class);
        $this->service()->detailFor(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $answerId,
            self::UNCITED_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );
    }

    public function testADocumentIdThatDoesNotExistIsNotFound(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->expectException(NotFoundException::class);
        $this->service()->detailFor(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $answerId,
            999999,
            ChatRetrievalScope::StoreKnowledge,
        );
    }

    /**
     * A citation of one answer does not unlock the same document through a different answer that never
     * cited it — the gate is per message, not per thread.
     */
    public function testACitationOfOneAnswerDoesNotUnlockAnother(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId] = $this->seedAnsweredThread($participant);
        $bareAnswerId = $this->seedAnswer($conversationId, []);

        $this->expectException(NotFoundException::class);
        $this->service()->detailFor(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $bareAnswerId,
            self::CITED_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );
    }

    // ---------------------------------------------------------------- who owns the thread

    public function testAnotherAdminCannotReadTheSource(): void
    {
        $owner = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($owner);

        $this->expectException(NotFoundException::class);
        $this->service()->detailFor(
            $this->knowledgeBase(),
            ChatParticipant::admin(2),
            $conversationId,
            $answerId,
            self::CITED_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );
    }

    /**
     * The realm discriminator, not just the number: agent #1 is not admin #1.
     */
    public function testAnAgentWithTheSameNumericIdCannotReadAnAdminsSource(): void
    {
        $owner = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($owner);

        $this->expectException(NotFoundException::class);
        $this->service()->detailFor(
            $this->knowledgeBase(),
            ChatParticipant::agent(1),
            $conversationId,
            $answerId,
            self::CITED_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );
    }

    public function testAnotherAgentCannotReadTheSource(): void
    {
        $owner = ChatParticipant::agent(42);
        [$conversationId, $answerId] = $this->seedAnsweredThread($owner);

        $this->expectException(NotFoundException::class);
        $this->service()->detailFor(
            $this->knowledgeBase(),
            ChatParticipant::agent(43),
            $conversationId,
            $answerId,
            self::CITED_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );
    }

    /**
     * The same conversation id read through a different store is not found — a store an agent may not open
     * cannot become reachable by guessing a thread id.
     */
    public function testTheSameThreadIsNotReachableThroughAnotherKnowledgeBase(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->expectException(NotFoundException::class);
        $this->service()->detailFor(
            $this->knowledgeBase(self::KB_OTHER),
            $participant,
            $conversationId,
            $answerId,
            self::CITED_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );
    }

    // ---------------------------------------------------------------- which message

    public function testAQuestionHasNoSources(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, , $questionId] = $this->seedAnsweredThread($participant);

        $this->expectException(NotFoundException::class);
        $this->service()->detailFor(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $questionId,
            self::CITED_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );
    }

    /**
     * A superseded answer is hidden from the thread by an edit. Its sources must become unreachable with
     * it — being merely unlinked in the UI is not enough.
     */
    public function testASupersededAnswerExposesNoSources(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId, $questionId] = $this->seedAnsweredThread($participant);

        $this->messages->supersedeAnswersFor($questionId, $this->now);

        $this->expectException(NotFoundException::class);
        $this->service()->detailFor(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $answerId,
            self::CITED_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );
    }

    public function testAForgedMessageIdIsNotFound(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId] = $this->seedAnsweredThread($participant);

        $this->expectException(NotFoundException::class);
        $this->service()->detailFor(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            999999,
            self::CITED_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );
    }

    // ---------------------------------------------------------------- retrieval scope

    /**
     * Even a genuinely cited rule projection is refused through a Store Chat, whose scope excludes rules.
     * Belt and braces: the resolver should never have produced such a citation in the first place.
     */
    public function testStoreChatCannotReadARuleProjection(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant, self::RULE_DOC);

        $this->expectException(NotFoundException::class);
        $this->service()->detailFor(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $answerId,
            self::RULE_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );
    }

    public function testRuleChatCannotReadStoreKnowledge(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $this->expectException(NotFoundException::class);
        $this->service()->detailFor(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $answerId,
            self::CITED_DOC,
            ChatRetrievalScope::RuleOnly,
        );
    }

    // ---------------------------------------------------------------- store profile visibility

    /**
     * The existing operator switch governs this endpoint too: with Store Profile documents hidden, a cited
     * profile is not readable here either.
     */
    public function testAHiddenStoreProfileDocumentIsNotFound(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant, self::PROFILE_DOC);

        $this->expectException(NotFoundException::class);
        $this->service(showStoreProfiles: false)->detailFor(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $answerId,
            self::PROFILE_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );
    }

    public function testAStoreProfileDocumentIsReadableWhenTheOperatorShowsThem(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant, self::PROFILE_DOC);

        $item = $this->service(showStoreProfiles: true)->detailFor(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $answerId,
            self::PROFILE_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );

        assertSame('Store profile', $item->title);
    }

    // ---------------------------------------------------------------- what is returned

    /**
     * The read model carries no provider or storage identifiers at all, so the JSON built from it cannot
     * leak one by accident.
     */
    public function testTheReturnedItemCarriesNoInternalIdentifiers(): void
    {
        $participant = ChatParticipant::admin(1);
        [$conversationId, $answerId] = $this->seedAnsweredThread($participant);

        $item = $this->service()->detailFor(
            $this->knowledgeBase(),
            $participant,
            $conversationId,
            $answerId,
            self::CITED_DOC,
            ChatRetrievalScope::StoreKnowledge,
        );

        $fields = array_keys(get_object_vars($item));
        foreach (['fileId', 'openaiFileId', 'vectorStoreId', 'storedPath', 'storageToken', 'checksumSha256'] as $forbidden) {
            assertTrue(!in_array($forbidden, $fields, true), 'Read model exposes ' . $forbidden);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function service(bool $showStoreProfiles = false): ShowChatSourceService
    {
        $canonical = new ServeCanonicalDocumentService(
            $this->documents,
            new InMemoryDocumentStorage(),
            new ResponseFactory(),
        );

        return new ShowChatSourceService(
            $this->conversations,
            $this->messages,
            new ChatKnowledgeSourcesService(
                $this->textDocuments,
                $this->documents,
                $canonical,
                new Order58DisplayParams($showStoreProfiles),
            ),
        );
    }

    private function knowledgeBase(int $id = self::KB): KnowledgeBase
    {
        return new KnowledgeBase(
            id: $id,
            name: 'Store',
            slug: 'store',
            description: null,
            systemInstructions: null,
            openaiVectorStoreId: 'vs_test',
            vectorStoreStatus: VectorStoreStatus::Ready,
            vectorStoreError: null,
            status: KnowledgeBaseStatus::Active,
            createdAt: $this->now,
            updatedAt: $this->now,
        );
    }

    /**
     * A thread with one question and one answer citing $documentId.
     *
     * @return array{0: int, 1: int, 2: int} conversation id, answer id, question id
     */
    private function seedAnsweredThread(ChatParticipant $participant, int $documentId = self::CITED_DOC): array
    {
        $conversationId = $this->conversations->createThread(self::KB, $participant, 'Thread', $this->now);
        $questionId = $this->messages->add(NewMessage::user($conversationId, 'A question?'), $this->now);
        $answerId = $this->seedAnswer(
            $conversationId,
            [new ResolvedCitation($documentId, 'source.md', 'file-abc')],
            $questionId,
        );

        return [$conversationId, $answerId, $questionId];
    }

    /**
     * @param list<ResolvedCitation> $citations
     */
    private function seedAnswer(int $conversationId, array $citations, ?int $replyTo = null): int
    {
        return $this->messages->insertActiveAnswer(
            new NewMessage(
                conversationId: $conversationId,
                role: MessageRole::Assistant,
                content: 'An answer.',
                citations: $citations,
                isGrounded: true,
                replyToMessageId: $replyTo,
            ),
            $this->now,
        );
    }

    /**
     * Seeded in both stores the sources service reads: the text listing it enumerates, and the canonical
     * record it reads the body from. Manual-text-style bodies keep the fake storage out of it.
     */
    private function seedDocument(int $id, DocumentSourceType $sourceType, string $title, string $body): void
    {
        $this->textDocuments->seed($id, self::KB, $sourceType, $title, $body, 'sum' . $id, 'kb/' . $id . '.md');
        $this->documents->seed($id, self::KB, DocumentStatus::Ready);
        $this->documents->seedCanonical(new CanonicalDocument(
            id: $id,
            knowledgeBaseId: self::KB,
            sourceType: DocumentSourceType::ManualText,
            kind: DocumentKind::Text,
            status: DocumentStatus::Ready,
            title: $title,
            originalFilename: $id . '.md',
            storedPath: 'kb/' . $id . '.md',
            storageToken: 'tok' . $id,
            mimeType: 'text/markdown',
            extension: 'md',
            sizeBytes: strlen($body),
            checksumSha256: str_repeat('a', 64),
            sourceText: $body,
            sourceRef: null,
            isSourceOverridden: false,
        ));
    }
}
