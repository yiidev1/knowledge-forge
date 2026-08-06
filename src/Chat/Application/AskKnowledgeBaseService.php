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
use App\Chat\Domain\ConversationRepositoryInterface;
use App\Chat\Domain\Exception\LatestQuestionUnanswered;
use App\Chat\Domain\Exception\QuestionInvalid;
use App\Chat\Domain\MessageRepositoryInterface;
use App\Chat\Domain\MessageRole;
use App\Chat\Domain\NewMessage;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\DocumentSourceType;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\RuleRepositoryInterface;
use App\Rules\Application\CommonRulesReadiness;
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
        private ChatAvailabilityPolicy $availability,
        private ChatCompletionProviderInterface $provider,
        private InstructionBuilder $instructionBuilder,
        private ConversationHistoryPolicyInterface $historyPolicy,
        private CitationResolver $citationResolver,
        private GroundingVerifier $verifier,
        private ClockInterface $clock,
        private ChatParams $params,
        private ExhaustiveIntentDetector $intentDetector,
        private LoggerInterface $logger,
        private CommonRulesReadiness $commonRules,
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

        $this->guardLatestAnswered($conversation->id);
        $this->askNewQuestion($knowledgeBase, $conversation->id, $question);

        return $conversation->id;
    }

    /**
     * Answers a question within an existing conversation.
     */
    public function ask(KnowledgeBase $knowledgeBase, int $conversationId, string $question): void
    {
        $question = $this->validateQuestion($question);
        $this->assertChatAvailable($knowledgeBase);
        $this->guardLatestAnswered($conversationId);

        $this->askNewQuestion($knowledgeBase, $conversationId, $question);
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
    ): void {
        $history = $this->historyPolicy->select(
            $this->messages->findActiveBefore($conversationId, $editedMessageId),
        );

        $this->generateAndStoreAnswer($knowledgeBase, $conversationId, $prompt, $history, $editedMessageId);
    }

    /**
     * Stores a new user question, then generates and stores its answer. History is captured before the new
     * question is persisted.
     */
    private function askNewQuestion(KnowledgeBase $knowledgeBase, int $conversationId, string $question): void
    {
        $history = $this->historyPolicy->select($this->messages->findByConversation($conversationId));

        $userMessageId = $this->messages->add(NewMessage::user($conversationId, $question), $this->clock->now());

        $this->generateAndStoreAnswer($knowledgeBase, $conversationId, $question, $history, $userMessageId);
    }

    /**
     * The generate-and-store core shared by asking and regenerating, with a strict two-stage retrieval order:
     *
     *   1. Answer from THIS store's knowledge base. If grounded, store it (store_knowledge, or store_rule when
     *      the winning citation is a store rule) — exactly as before. The store always has priority.
     *   2. Only when the store produces no grounded answer, and a ready hidden Global Rules base exists, ask it.
     *      That base holds a projection of EVERY approved rule (store-specific or common), so a rule mapped to
     *      another store can answer here. A grounded global answer is stored (global_rule). A store-vector-store
     *      infrastructure failure propagates (the store is authoritative); a *global*-stage infrastructure
     *      failure is logged and degrades to the store's fallback rather than failing the whole request.
     *   3. Otherwise store the store's ungrounded fallback (fallback).
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
    ): void {
        // Stage 1 — the store's own knowledge base. A provider failure here propagates (temporarily unavailable).
        $storeAttempt = $this->produceAnswer($knowledgeBase, $conversationId, $prompt, $history);

        if ($storeAttempt->outcome->isGrounded) {
            $this->persistAnswer($conversationId, $storeAttempt, $replyToMessageId, $this->storeAnswerSource($storeAttempt));

            return;
        }

        // Stage 2 — the hidden Global Rules base (every approved rule), only when it is ready. Never affects
        // store availability. A store-specific rule mapped to another store answers here, and only here.
        $globalBase = $this->commonRules->readyKnowledgeBase();
        if ($globalBase !== null) {
            $globalAttempt = $this->tryProduceCommon($globalBase, $conversationId, $prompt, $history);
            if ($globalAttempt !== null && $globalAttempt->outcome->isGrounded) {
                $this->persistAnswer($conversationId, $globalAttempt, $replyToMessageId, AnswerSource::GlobalRule);

                return;
            }
        }

        // Stage 3 — the store's ungrounded fallback.
        $this->persistAnswer($conversationId, $storeAttempt, $replyToMessageId, AnswerSource::Fallback);
    }

    /**
     * Asks one knowledge base with forced retrieval, resolves citations and verifies grounding — WITHOUT
     * persisting anything. Throws {@see AiException} on a provider failure.
     *
     * @param list<\App\Ai\Contract\Dto\ChatMessage> $history
     */
    private function produceAnswer(KnowledgeBase $knowledgeBase, int $conversationId, string $prompt, array $history): AnswerAttempt
    {
        // "Every match" needs a wider net and different instructions than "the best match": one relevance
        // query returns the closest chunks, which cannot enumerate a set.
        $exhaustive = $this->intentDetector->isExhaustive($prompt);

        $instructions = $this->instructionBuilder->build(
            $knowledgeBase->systemInstructions(),
            $this->rules->findEnabledForKnowledgeBase($knowledgeBase->id()),
            $this->params->fallbackMessage,
            $exhaustive,
        );

        // Safe by construction: assertChatAvailable() (store) / readiness (common) required a ready store.
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

        $citations = $this->citationResolver->resolve($result->citations, $knowledgeBase->id());
        $outcome = $this->verifier->verify($result, $citations);

        $this->logAnswer($knowledgeBase, $conversationId, $result, $outcome, $exhaustive, $startedAt);

        return new AnswerAttempt($result, $outcome);
    }

    /**
     * Stage-2 produce that never breaks the request: a common-base infrastructure failure is logged and
     * degrades to the store's fallback (we already hold a valid ungrounded stage-1 result).
     *
     * @param list<\App\Ai\Contract\Dto\ChatMessage> $history
     */
    private function tryProduceCommon(KnowledgeBase $commonBase, int $conversationId, string $prompt, array $history): ?AnswerAttempt
    {
        try {
            return $this->produceAnswer($commonBase, $conversationId, $prompt, $history);
        } catch (AiException $e) {
            $this->logger->warning('common-rules fallback failed', [
                'conversation_id' => $conversationId,
                'error' => $e::class,
            ]);

            return null;
        }
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

    /**
     * A grounded store answer is `store_rule` when its selected (highest-ranked) resolved citation is a store
     * rule document, otherwise `store_knowledge` [clarification 4].
     */
    private function storeAnswerSource(AnswerAttempt $attempt): AnswerSource
    {
        $top = $attempt->outcome->citations[0] ?? null;
        if ($top !== null && $this->documents->sourceTypeOfDocument($top->documentId) === DocumentSourceType::Order58RuleStore->value) {
            return AnswerSource::StoreRule;
        }

        return AnswerSource::StoreKnowledge;
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
     * Guards that the base can actually answer, via the one canonical {@see ChatAvailabilityPolicy}: a
     * provisioned store, a usable qualifying (non-store-profile) document, and — for an Order58-linked base
     * — a usable store-profile snapshot. Public so the edit flow refuses to regenerate against an
     * unavailable base; both surfaces and every server-side chat operation share this single rule.
     */
    public function assertChatAvailable(KnowledgeBase $knowledgeBase): void
    {
        $this->availability->assertAvailable($knowledgeBase);
    }
}
