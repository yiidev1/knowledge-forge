<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\CurrentAgent;
use App\Agent\Domain\AgentIdentity;
use App\Agent\Domain\AgentStore;
use App\Agent\Web\Chat\AgentStoreResolver;
use App\Agent\Web\Chat\StartAction;
use App\Chat\Application\AskKnowledgeBaseService;
use App\Chat\Application\ChatAvailabilityPolicy;
use App\Chat\Application\ChatParams;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Application\Citation\CitationResolver;
use App\Chat\Application\FindOrCreateThreadService;
use App\Chat\Application\Grounding\GroundingVerifier;
use App\Chat\Application\History\RecentMessagesHistoryPolicy;
use App\Chat\Application\Instruction\ImmutableSecurityInstructions;
use App\Chat\Application\Instruction\InstructionBuilder;
use App\Chat\Application\Retrieval\ExhaustiveIntentDetector;
use App\Rules\Application\CommonRulesReadiness;
use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Application\Correlation\CorrelationId;
use App\Shared\Infrastructure\Log\SafeLogContext;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use App\Tests\Support\Fake\Agent\InMemoryAgentStoreDirectory;
use App\Tests\Support\Fake\Ai\FakeChatCompletionProvider;
use App\Tests\Support\Fake\Chat\InMemoryConversationRepository;
use App\Tests\Support\Fake\Chat\InMemoryMessageRepository;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\Document\InMemoryIndexedFileRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseSourceRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryRuleRepository;
use App\Tests\Support\Fake\Shared\RecordingSession;
use App\Tests\Support\Fake\Shared\StubUrlGenerator;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use Psr\Log\NullLogger;
use Yiisoft\Session\Flash\Flash;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

/**
 * A direct agent question POST must be rejected server-side when {@see ChatAvailabilityPolicy} reports the
 * store unavailable — exercising the real agent {@see StartAction} through the same
 * {@see AskKnowledgeBaseService::assertChatAvailable()} guard the admin flow uses. (A full HTTP agent login
 * cannot run here: agents authenticate against the external Order58 API, so this drives the action directly
 * with an authenticated agent identity and an available store the policy nonetheless rejects.)
 */
final class AgentChatAvailabilityEnforcementTest extends Unit
{
    private const KB = 42;
    private const SLUG = 'wonderful-store';
    private const AGENT_ADMIN_ID = 5;

    private InMemoryConversationRepository $conversations;
    private InMemoryMessageRepository $messages;
    private InMemoryDocumentRepository $documents;
    private InMemoryKnowledgeBaseRepository $knowledgeBases;
    private InMemoryKnowledgeBaseSourceRepository $sources;
    private FakeChatCompletionProvider $provider;
    private RecordingSession $session;

    protected function _before(): void
    {
        $this->conversations = new InMemoryConversationRepository();
        $this->messages = new InMemoryMessageRepository();
        $this->documents = new InMemoryDocumentRepository();
        $this->knowledgeBases = new InMemoryKnowledgeBaseRepository();
        $this->sources = new InMemoryKnowledgeBaseSourceRepository();
        $this->provider = new FakeChatCompletionProvider();
        $this->session = new RecordingSession();

        // A ready, Order58-linked store whose only ready document is the profile → policy unavailable.
        $this->knowledgeBases->seedReady(self::KB, self::SLUG, 'vs_1');
        $this->sources->linkToOrder58(self::KB, 1491);
        $this->documents->setUsableStoreProfile(self::KB, true);
        $this->documents->setUsableQualifyingDocument(self::KB, false);
    }

    public function testDirectAgentQuestionPostIsRejectedWhenUnavailable(): void
    {
        $response = ($this->startAction())(
            self::SLUG,
            (new ServerRequest())->withParsedBody(['question' => 'What are your hours?']),
        );

        // The question never reached the AI: the availability guard blocked it before any provider call…
        assertNull($this->provider->lastRequest, 'agent question must be rejected before the provider is called');
        // …no answer was persisted…
        assertSame(0, $this->totalMessages(), 'no message may be persisted for an unavailable store');
        // …and the agent is redirected (PRG, 303 See Other) with the failure flashed.
        assertSame(303, $response->getStatusCode());
    }

    private function totalMessages(): int
    {
        $conversation = $this->conversations->findThread(self::KB, ChatParticipant::agent(self::AGENT_ADMIN_ID));

        return $conversation === null ? 0 : count($this->messages->findByConversation($conversation->id));
    }

    private function startAction(): StartAction
    {
        $currentAgent = new CurrentAgent();
        $currentAgent->set(new AgentIdentity(
            adminId: self::AGENT_ADMIN_ID,
            username: 'agent',
            displayName: 'Agent',
            email: null,
            status: 'active',
            userType: 'agent',
        ));

        $directory = new InMemoryAgentStoreDirectory();
        $directory->add(new AgentStore(knowledgeBaseId: self::KB, slug: self::SLUG, name: 'Wonderful Store'));

        $clock = new MutableClock();

        return new StartAction(
            $this->askService($clock),
            new FindOrCreateThreadService($this->conversations, $clock),
            new AgentStoreResolver(new KnowledgeBaseFinder($this->knowledgeBases), $directory),
            $currentAgent,
            $this->params(),
            new Redirect(new ResponseFactory(), new StubUrlGenerator()),
            new FlashMessages(new Flash($this->session)),
            $this->session,
        );
    }

    private function askService(MutableClock $clock): AskKnowledgeBaseService
    {
        $params = $this->params();

        return new AskKnowledgeBaseService(
            $this->conversations,
            new FindOrCreateThreadService($this->conversations, $clock),
            $this->messages,
            new InMemoryRuleRepository(),
            new ChatAvailabilityPolicy($this->documents, $this->sources),
            $this->provider,
            new InstructionBuilder(new ImmutableSecurityInstructions()),
            new RecentMessagesHistoryPolicy(10, 8000),
            new CitationResolver(
                new InMemoryIndexedFileRepository(),
                $this->documents,
                new NullLogger(),
                new SafeLogContext(new SecretRedactor(), new CorrelationId('corr-test')),
            ),
            new GroundingVerifier($params),
            $clock,
            $params,
            new ExhaustiveIntentDetector(),
            new NullLogger(),
            new CommonRulesReadiness(new EnsureCommonRulesKnowledgeBaseService(new InMemoryKnowledgeBaseRepository()), $this->documents),
            $this->documents,
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
            fallbackMessage: 'fallback',
            maxQuestionLength: 2000,
        );
    }
}
