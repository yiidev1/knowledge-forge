<?php

declare(strict_types=1);

namespace App\Chat\Application;

use App\Ai\Contract\ChatCompletionProviderInterface;
use App\Ai\Contract\Dto\GroundedAnswerRequest;
use App\Ai\Contract\Dto\GroundedAnswerResult;
use App\Chat\Application\Citation\CitationResolver;
use App\Chat\Application\Grounding\GroundingOutcome;
use App\Chat\Application\Grounding\GroundingVerifier;
use App\Chat\Application\History\ConversationHistoryPolicyInterface;
use App\Chat\Application\Instruction\InstructionBuilder;
use App\Chat\Application\Retrieval\ExhaustiveIntentDetector;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ConversationRepositoryInterface;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Domain\Exception\QuestionInvalid;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Domain\MessageRole;
use App\Chat\Domain\NewMessage;
use App\Document\Domain\DocumentRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\RuleRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

use function count;
use function mb_strlen;
use function microtime;
use function substr;
use function trim;

/**
 * Answers a question against a knowledge base, grounded in its documents.
 *
 * The one place the web tier calls OpenAI synchronously. The flow: guard the base is ready and the
 * question is valid → persist the question → build instructions (immutable block + KB rules) and bounded
 * history → ask the provider with forced retrieval → resolve citations back to documents → verify
 * grounding (an uncited or unretrieved answer becomes the fallback) → persist the assistant message with
 * its verdict. A provider failure propagates as an {@see \App\Ai\Contract\Exception\AiException} for the
 * action to surface; the question stays recorded so the thread still makes sense.
 */
final readonly class AskKnowledgeBaseService
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private FindOrCreateThreadService $threads,
        private MessageRepositoryInterface $messages,
        private RuleRepositoryInterface $rules,
        private DocumentRepositoryInterface $documents,
        private ChatCompletionProviderInterface $provider,
        private InstructionBuilder $instructionBuilder,
        private ConversationHistoryPolicyInterface $historyPolicy,
        private CitationResolver $citationResolver,
        private GroundingVerifier $verifier,
        private ClockInterface $clock,
        private ChatParams $params,
        private ExhaustiveIntentDetector $intentDetector,
        private LoggerInterface $logger,
    ) {}

    /**
     * Records what retrieval and grounding actually did, as safe metadata only.
     *
     * Without this the outcome is unrecoverable after the fact: the stored answer text is identical
     * whether the verifier rejected it or the model refused on its own, and `store: false` means the
     * response cannot be fetched back from the provider. Nothing here carries document content, question
     * text, credentials or full identifiers — `SafeLogContext` drops any key not on its allowlist.
     */
    private function logAnswer(
        KnowledgeBase $knowledgeBase,
        int $conversationId,
        GroundedAnswerResult $result,
        GroundingOutcome $outcome,
        bool $exhaustive,
        float $startedAt,
    ): void {
        $this->logger->info('chat answer', [
            'knowledge_base_id' => $knowledgeBase->id(),
            'conversation_id' => $conversationId,
            'vector_store_suffix' => substr((string) $knowledgeBase->openaiVectorStoreId(), -6),
            'openai_response_id' => $result->providerResponseId,
            'model' => $result->model,
            'exhaustive_intent' => $exhaustive,
            'max_results' => $exhaustive ? $this->params->exhaustiveMaxResults : $this->params->maxResults,
            'max_output_tokens' => $this->params->maxOutputTokens,
            'reasoning_effort' => $this->params->reasoningEffort,
            'response_status' => $result->responseStatus,
            'incomplete_reason' => $result->incompleteReason,
            'retrieval_called' => $result->retrievalCalled,
            'retrieval_status' => $result->retrievalStatus,
            'search_call_count' => $result->searchCallCount,
            'completed_search_call_count' => $result->completedSearchCallCount,
            'result_count' => $result->retrievalResultCount,
            'top_score' => $result->topResultScore,
            // Raw annotations returned, and the distinct documents they resolved to. These two are NOT
            // subtractable: several chunks of one document each carry their own annotation and collapse
            // to a single citation by design, so "16 annotations, 1 citation" is a healthy result, not
            // 15 failures. Genuine resolution failures are logged individually by CitationResolver with
            // the file id and the reason, under the same correlation id.
            'annotation_count' => count($result->citations),
            'resolved_citation_count' => count($outcome->citations),
            'grounding_reason' => $outcome->rejectionReason,
            'is_grounded' => $outcome->isGrounded,
            'output_tokens' => $result->usage->outputTokens,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);
    }

    /**
     * Asks the first (or next) question on the participant's canonical thread for this knowledge base.
     * Finds or creates that conversation; never mints a new thread when one exists.
     *
     * @return int The canonical conversation id.
     */
    public function startConversation(
        KnowledgeBase $knowledgeBase,
        string $question,
        ChatParticipant $participant,
    ): int {
        $question = $this->validateQuestion($question);
        $this->assertChatAvailable($knowledgeBase);

        $conversation = $this->threads->findOrCreate(
            $knowledgeBase->id(),
            $participant,
            $knowledgeBase->name(),
        );

        $this->answer($knowledgeBase, $conversation->id, $question);

        return $conversation->id;
    }

    /**
     * Answers a question within an existing conversation.
     */
    public function ask(KnowledgeBase $knowledgeBase, int $conversationId, string $question): void
    {
        $question = $this->validateQuestion($question);
        $this->assertChatAvailable($knowledgeBase);

        $this->answer($knowledgeBase, $conversationId, $question);
    }

    private function answer(KnowledgeBase $knowledgeBase, int $conversationId, string $question): void
    {
        // Prior turns, captured before the new question is stored.
        $history = $this->historyPolicy->select($this->messages->findByConversation($conversationId));

        $this->messages->add(NewMessage::user($conversationId, $question), $this->clock->now());

        // "Every match" needs a wider net and different instructions than "the best match": one relevance
        // query returns the closest chunks, which cannot enumerate a set.
        $exhaustive = $this->intentDetector->isExhaustive($question);

        $instructions = $this->instructionBuilder->build(
            $knowledgeBase->systemInstructions(),
            $this->rules->findEnabledForKnowledgeBase($knowledgeBase->id()),
            $this->params->fallbackMessage,
            $exhaustive,
        );

        // Safe by construction: assertChatAvailable() has already required a ready store.
        $vectorStoreId = (string) $knowledgeBase->openaiVectorStoreId();

        $startedAt = microtime(true);

        $result = $this->provider->ask(new GroundedAnswerRequest(
            model: $this->params->model,
            instructions: $instructions,
            history: $history,
            question: $question,
            vectorStoreId: $vectorStoreId,
            maxResults: $exhaustive ? $this->params->exhaustiveMaxResults : $this->params->maxResults,
            maxOutputTokens: $this->params->maxOutputTokens,
            forceFileSearch: $this->params->forceFileSearch,
            reasoningEffort: $this->params->reasoningEffort,
        ));

        $citations = $this->citationResolver->resolve($result->citations, $knowledgeBase->id());
        $outcome = $this->verifier->verify($result, $citations);

        $this->logAnswer($knowledgeBase, $conversationId, $result, $outcome, $exhaustive, $startedAt);

        $this->messages->add(
            new NewMessage(
                conversationId: $conversationId,
                role: MessageRole::Assistant,
                content: $outcome->text,
                citations: $outcome->citations,
                usage: $result->usage->toArray(),
                isGrounded: $outcome->isGrounded,
                retrievalStatus: $outcome->retrievalStatus,
                providerResponseId: $result->providerResponseId,
                model: $result->model,
            ),
            $this->clock->now(),
        );

        $this->conversations->touch($conversationId, $this->clock->now());
    }

    private function validateQuestion(string $question): string
    {
        $question = trim($question);

        if ($question === '') {
            throw QuestionInvalid::empty();
        }

        if (mb_strlen($question) > $this->params->maxQuestionLength) {
            throw QuestionInvalid::tooLong($this->params->maxQuestionLength);
        }

        return $question;
    }

    private function assertChatAvailable(KnowledgeBase $knowledgeBase): void
    {
        if (!$knowledgeBase->isReadyForChat()) {
            throw ChatUnavailable::notProvisioned();
        }

        if ($this->documents->countReadyForKnowledgeBase($knowledgeBase->id()) < 1) {
            throw ChatUnavailable::noReadyDocuments();
        }
    }

}
