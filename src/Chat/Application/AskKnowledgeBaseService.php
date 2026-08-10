<?php

declare(strict_types=1);

namespace App\Chat\Application;

use App\Ai\Contract\ChatCompletionProviderInterface;
use App\Ai\Contract\Dto\GroundedAnswerRequest;
use App\Ai\Contract\Dto\GroundedAnswerResult;
use App\Ai\Contract\Exception\AiException;
use App\Chat\Application\Citation\CitationResolver;
use App\Chat\Application\Grounding\GroundingOutcome;
use App\Chat\Application\Grounding\GroundingVerifier;
use App\Chat\Application\History\ConversationHistoryPolicyInterface;
use App\Chat\Application\Instruction\InstructionBuilder;
use App\Chat\Application\Retrieval\ExhaustiveIntentDetector;
use App\Chat\Domain\AnswerSource;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ChatRetrievalScope;
use App\Chat\Domain\ConversationRepositoryInterface;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Domain\Exception\LatestQuestionUnanswered;
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
 *
 * {@see ChatRetrievalScope} selects Store Chat vs Rule Chat citation/instruction behaviour. Store Chat never
 * consults the hidden global rules base; Rule Chat answers only against that base with rule-only scope.
 */
final readonly class AskKnowledgeBaseService
{
    public function __construct(
        private ConversationRepositoryInterface $conversations,
        private FindOrCreateThreadService $threads,
        private MessageRepositoryInterface $messages,
        private RuleRepositoryInterface $rules,
        private ChatAvailabilityPolicy $availability,
        private RuleChatAvailability $ruleChatAvailability,
        private ChatCompletionProviderInterface $provider,
        private InstructionBuilder $instructionBuilder,
        private ConversationHistoryPolicyInterface $historyPolicy,
        private CitationResolver $citationResolver,
        private GroundingVerifier $verifier,
        private ClockInterface $clock,
        private ChatParams $params,
        private ExhaustiveIntentDetector $intentDetector,
        private LoggerInterface $logger,
        private DocumentRepositoryInterface $documents,
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
        ChatRetrievalScope $scope,
    ): void {
        $this->logger->info('chat answer', [
            'knowledge_base_id' => $knowledgeBase->id(),
            'conversation_id' => $conversationId,
            'retrieval_scope' => $scope->value,
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
        ChatRetrievalScope $scope = ChatRetrievalScope::StoreKnowledge,
    ): int {
        $question = $this->validateQuestion($question);
        $this->assertChatAvailable($knowledgeBase, $scope);

        $conversation = $this->threads->findOrCreate(
            $knowledgeBase->id(),
            $participant,
            $knowledgeBase->name(),
        );

        $this->guardLatestAnswered($conversation->id);
        $this->askNewQuestion($knowledgeBase, $conversation->id, $question, $scope);

        return $conversation->id;
    }

    /**
     * Answers a question within an existing conversation.
     */
    public function ask(
        KnowledgeBase $knowledgeBase,
        int $conversationId,
        string $question,
        ChatRetrievalScope $scope = ChatRetrievalScope::StoreKnowledge,
    ): void {
        $question = $this->validateQuestion($question);
        $this->assertChatAvailable($knowledgeBase, $scope);
        $this->guardLatestAnswered($conversationId);

        $this->askNewQuestion($knowledgeBase, $conversationId, $question, $scope);
    }

    /**
     * Regenerates the answer to a question that was just edited. The edited user message already exists and
     * its previous answer has already been superseded by the caller; this stores a fresh answer linked back
     * to that question. History is the active turns *before* the edited message, so the superseded answer
     * and anything after it never reach the model. A provider failure propagates as an
     * {@see \App\Ai\Contract\Exception\AiException}; the caller decides how to surface the retry state.
     */
    public function regenerateForEditedMessage(
        KnowledgeBase $knowledgeBase,
        int $conversationId,
        int $editedMessageId,
        string $prompt,
        ChatRetrievalScope $scope = ChatRetrievalScope::StoreKnowledge,
    ): void {
        $history = $this->historyPolicy->select(
            $this->messages->findActiveBefore($conversationId, $editedMessageId),
        );

        $this->generateAndStoreAnswer($knowledgeBase, $conversationId, $prompt, $history, $editedMessageId, $scope);
    }

    /**
     * Stores a new user question, then generates and stores its answer. History is captured before the new
     * question is persisted.
     */
    private function askNewQuestion(
        KnowledgeBase $knowledgeBase,
        int $conversationId,
        string $question,
        ChatRetrievalScope $scope,
    ): void {
        $history = $this->historyPolicy->select($this->messages->findByConversation($conversationId));

        $userMessageId = $this->messages->add(NewMessage::user($conversationId, $question), $this->clock->now());

        $this->generateAndStoreAnswer($knowledgeBase, $conversationId, $question, $history, $userMessageId, $scope);
    }

    /**
     * The generate-and-store core shared by asking and regenerating. SINGLE-STAGE retrieval: answer only from
     * THIS knowledge base's own vector store. Grounded answers are labelled by {@see ChatRetrievalScope};
     * otherwise the ungrounded fallback is stored.
     *
     * Store chat deliberately never consults the hidden global/common rules base: rule content is served only
     * by the dedicated Rule Chat. Citation scope additionally rejects Order58 rule projections in Store Chat
     * and non-rule sources in Rule Chat.
     *
     * Exactly ONE assistant message is ever written, via {@see MessageRepositoryInterface::insertActiveAnswer()},
     * upholding the one-active-answer invariant even under a concurrent regeneration.
     *
     * @param list<\App\Ai\Contract\Dto\ChatMessage> $history
     */
    private function generateAndStoreAnswer(
        KnowledgeBase $knowledgeBase,
        int $conversationId,
        string $prompt,
        array $history,
        int $replyToMessageId,
        ChatRetrievalScope $scope,
    ): void {
        $attempt = $this->produceAnswer($knowledgeBase, $conversationId, $prompt, $history, $scope);

        $answerSource = $attempt->outcome->isGrounded
            ? $this->answerSourceFor($scope)
            : AnswerSource::Fallback;

        $this->persistAnswer($conversationId, $attempt, $replyToMessageId, $answerSource);
    }

    /**
     * Asks one knowledge base with forced retrieval, resolves citations and verifies grounding — WITHOUT
     * persisting anything. Throws {@see AiException} on a provider failure.
     *
     * @param list<\App\Ai\Contract\Dto\ChatMessage> $history
     */
    private function produceAnswer(
        KnowledgeBase $knowledgeBase,
        int $conversationId,
        string $prompt,
        array $history,
        ChatRetrievalScope $scope,
    ): AnswerAttempt {
        // "Every match" needs a wider net and different instructions than "the best match": one relevance
        // query returns the closest chunks, which cannot enumerate a set.
        $exhaustive = $this->intentDetector->isExhaustive($prompt);

        $instructions = $scope === ChatRetrievalScope::RuleOnly
            ? $this->instructionBuilder->buildForRuleChat($this->params->fallbackMessage, $exhaustive)
            : $this->instructionBuilder->build(
                $knowledgeBase->systemInstructions(),
                $this->rules->findEnabledForKnowledgeBase($knowledgeBase->id()),
                $this->params->fallbackMessage,
                $exhaustive,
            );

        // Safe by construction: assertChatAvailable() required a ready store.
        $vectorStoreId = (string) $knowledgeBase->openaiVectorStoreId();

        $startedAt = microtime(true);

        $result = $this->provider->ask(new GroundedAnswerRequest(
            model: $this->params->model,
            instructions: $instructions,
            history: $history,
            question: $prompt,
            vectorStoreId: $vectorStoreId,
            maxResults: $exhaustive ? $this->params->exhaustiveMaxResults : $this->params->maxResults,
            maxOutputTokens: $this->params->maxOutputTokens,
            forceFileSearch: $this->params->forceFileSearch,
            reasoningEffort: $this->params->reasoningEffort,
        ));

        $citations = $this->citationResolver->resolve($result->citations, $knowledgeBase->id(), $scope);
        $outcome = $this->verifier->verify($result, $citations);

        $this->logAnswer($knowledgeBase, $conversationId, $result, $outcome, $exhaustive, $startedAt, $scope);

        return new AnswerAttempt($result, $outcome);
    }

    private function persistAnswer(int $conversationId, AnswerAttempt $attempt, int $replyToMessageId, AnswerSource $answerSource): void
    {
        $this->messages->insertActiveAnswer(
            new NewMessage(
                conversationId: $conversationId,
                role: MessageRole::Assistant,
                content: $attempt->outcome->text,
                citations: $attempt->outcome->citations,
                usage: $attempt->result->usage->toArray(),
                isGrounded: $attempt->outcome->isGrounded,
                retrievalStatus: $attempt->outcome->retrievalStatus,
                providerResponseId: $attempt->result->providerResponseId,
                model: $attempt->result->model,
                replyToMessageId: $replyToMessageId,
                answerSource: $answerSource,
            ),
            $this->clock->now(),
        );

        $this->conversations->touch($conversationId, $this->clock->now());
    }

    private function answerSourceFor(ChatRetrievalScope $scope): AnswerSource
    {
        return match ($scope) {
            ChatRetrievalScope::RuleOnly => AnswerSource::GlobalRule,
            ChatRetrievalScope::StoreKnowledge => AnswerSource::StoreKnowledge,
        };
    }

    /**
     * Rejects a new question while the thread's latest question is still unanswered (regeneration in flight
     * or failed). Enforced here so the guard cannot be bypassed by posting directly, not only in the UI.
     */
    private function guardLatestAnswered(int $conversationId): void
    {
        if ($this->messages->hasUnansweredLatestUserMessage($conversationId)) {
            throw LatestQuestionUnanswered::create();
        }
    }

    /**
     * Trims and bounds a question. Public so the edit flow validates identically — one definition of what a
     * valid question is.
     */
    public function validateQuestion(string $question): string
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

    /**
     * Guards that the surface can answer. Store Chat uses {@see ChatAvailabilityPolicy}; Rule Chat uses
     * {@see RuleChatAvailability}. Public so the edit flow refuses to regenerate against an unavailable base.
     */
    public function assertChatAvailable(
        KnowledgeBase $knowledgeBase,
        ChatRetrievalScope $scope = ChatRetrievalScope::StoreKnowledge,
    ): void {
        if ($scope === ChatRetrievalScope::RuleOnly) {
            $ready = $this->ruleChatAvailability->assertAvailable();
            if ($ready->id() !== $knowledgeBase->id()) {
                throw ChatUnavailable::noIndexedRules();
            }

            return;
        }

        $this->availability->assertAvailable($knowledgeBase);
    }
}
