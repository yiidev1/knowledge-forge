<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Ai\Contract\ChatCompletionProviderInterface;
use App\Ai\Contract\Dto\GroundedAnswerRequest;
use App\Ai\Contract\Dto\GroundedAnswerResult;
use App\Ai\Contract\Dto\IndexStatus;
use App\Ai\Contract\Dto\RawCitation;
use App\Chat\Application\AskKnowledgeBaseService;
use App\Chat\Application\ChatParams;
use App\Chat\Application\Citation\CitationResolver;
use App\Chat\Application\Grounding\GroundingVerifier;
use App\Chat\Application\History\RecentMessagesHistoryPolicy;
use App\Chat\Application\Instruction\ImmutableSecurityInstructions;
use App\Chat\Application\Instruction\InstructionBuilder;
use App\Chat\Application\Retrieval\ExhaustiveIntentDetector;
use App\Chat\Web\Ask\Action;
use App\Chat\Web\ConversationFinder;
use App\Document\Domain\DocumentStatus;
use App\Document\Domain\IndexedFileRole;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Application\Correlation\CorrelationId;
use App\Shared\Infrastructure\Log\SafeLogContext;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use App\Tests\Support\Fake\Chat\InMemoryConversationRepository;
use App\Tests\Support\Fake\Chat\InMemoryMessageRepository;
use App\Tests\Support\Fake\Document\InMemoryDocumentRepository;
use App\Tests\Support\Fake\Document\InMemoryIndexedFileRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseRepository;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryRuleRepository;
use App\Tests\Support\Fake\Shared\RecordingSession;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use Psr\Log\NullLogger;
use Stringable;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\Flash\Flash;

use function array_search;
use function PHPUnit\Framework\assertLessThan;
use function PHPUnit\Framework\assertNotFalse;
use function PHPUnit\Framework\assertSame;

/**
 * The chat action must release the PHP session lock BEFORE it calls the AI provider.
 *
 * PHP's `files` session handler holds an exclusive lock from session_start() until the session is
 * written, and the CSRF middleware opens the session before the router runs. Without an explicit close,
 * that lock is held across the entire OpenAI round trip and every other request from the same logged-in
 * browser blocks behind it — the reported "second tab hangs" symptom.
 *
 * Ordering is the whole property, so it is asserted on a single interleaved timeline rather than by
 * counting calls: `session.close` must appear before `provider.ask`.
 */
final class ChatSessionReleaseTest extends Unit
{
    private const KB = 7;
    private const DOC = 5;

    private RecordingSession $session;
    private InMemoryConversationRepository $conversations;
    private InMemoryKnowledgeBaseRepository $knowledgeBases;
    private InMemoryMessageRepository $messages;
    private InMemoryDocumentRepository $documents;
    private InMemoryIndexedFileRepository $indexedFiles;
    private InMemoryRuleRepository $rules;
    private int $conversationId;

    protected function _before(): void
    {
        $this->session = new RecordingSession();
        $this->conversations = new InMemoryConversationRepository();
        $this->knowledgeBases = new InMemoryKnowledgeBaseRepository();
        $this->messages = new InMemoryMessageRepository();
        $this->documents = new InMemoryDocumentRepository();
        $this->indexedFiles = new InMemoryIndexedFileRepository();
        $this->rules = new InMemoryRuleRepository();

        $this->knowledgeBases->seedReady(self::KB, 'hr-docs', 'vs_1');
        $this->documents->seed(self::DOC, self::KB, DocumentStatus::Ready);
        $fileId = $this->indexedFiles->createPending(self::DOC, IndexedFileRole::DerivedMarkdown, 'derived/x.md');
        $this->indexedFiles->setUploaded($fileId, 'file_1', IndexStatus::Completed);

        $this->conversationId = $this->conversations->create(self::KB, 'A thread', (new MutableClock())->now());
    }

    public function testSessionIsClosedBeforeTheProviderIsCalled(): void
    {
        ($this->action())(
            'hr-docs',
            $this->conversationId,
            (new ServerRequest())->withParsedBody(['question' => 'What is the policy?']),
        );

        $closedAt = array_search('session.close', $this->session->events, true);
        $askedAt = array_search('provider.ask', $this->session->events, true);

        assertNotFalse($closedAt, 'The action never closed the session: ' . implode(', ', $this->session->events));
        assertNotFalse($askedAt, 'The provider was never called.');
        assertLessThan($askedAt, $closedAt, 'The session lock was still held during the provider call.');
    }

    /**
     * Closing must not cost the user their login: `close()` keeps the session id, so the middleware sees
     * an unchanged id afterwards and issues no new cookie. Regenerating here would silently log them out.
     */
    public function testClosingKeepsTheSessionIdentityIntact(): void
    {
        ($this->action())(
            'hr-docs',
            $this->conversationId,
            (new ServerRequest())->withParsedBody(['question' => 'What is the policy?']),
        );

        assertSame('session-id', $this->session->getId());
        assertSame(false, in_array('session.regenerateId', $this->session->events, true));
        assertSame(false, in_array('session.destroy', $this->session->events, true));
    }

    private function action(): Action
    {
        return new Action(
            $this->askService(),
            new KnowledgeBaseFinder($this->knowledgeBases),
            new ConversationFinder($this->conversations),
            new Redirect(new ResponseFactory(), $this->urlGenerator()),
            new FlashMessages(new Flash($this->session)),
            $this->session,
        );
    }

    private function askService(): AskKnowledgeBaseService
    {
        $params = new ChatParams(
            model: 'fake-chat',
            maxResults: 8,
            maxOutputTokens: 4000,
            forceFileSearch: true,
            requireCitations: true,
            minCitationScore: 0.0,
            fallbackMessage: 'No answer.',
            maxQuestionLength: 2000,
        );

        return new AskKnowledgeBaseService(
            $this->conversations,
            $this->messages,
            $this->rules,
            $this->documents,
            $this->recordingProvider(),
            new InstructionBuilder(new ImmutableSecurityInstructions()),
            new RecentMessagesHistoryPolicy(10, 8000),
            new CitationResolver(
                $this->indexedFiles,
                $this->documents,
                new NullLogger(),
                new SafeLogContext(new SecretRedactor(), new CorrelationId('corr-test')),
            ),
            new GroundingVerifier($params),
            new MutableClock(),
            $params,
            new ExhaustiveIntentDetector(),
            new NullLogger(),
        );
    }

    /**
     * A provider that writes onto the session's timeline, so "did the close happen first" is answerable
     * from one ordered list instead of two unrelated call logs.
     */
    private function recordingProvider(): ChatCompletionProviderInterface
    {
        $session = $this->session;

        return new class ($session) implements ChatCompletionProviderInterface {
            public function __construct(private RecordingSession $session) {}

            public function ask(GroundedAnswerRequest $request): GroundedAnswerResult
            {
                $this->session->record('provider.ask');

                return new GroundedAnswerResult(
                    text: 'An answer.',
                    retrievalCalled: true,
                    retrievalStatus: 'completed',
                    retrievalResultCount: 2,
                    topResultScore: 0.8,
                    citations: [new RawCitation('file_1', 'doc.pdf', 0)],
                    usage: \App\Ai\Contract\Dto\TokenUsage::zero(),
                    providerResponseId: 'resp_1',
                    model: 'fake-chat',
                );
            }
        };
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            public function generate(
                string $name,
                array $arguments = [],
                array $queryParameters = [],
                ?string $hash = null,
            ): string {
                return '/' . $name;
            }

            public function generateAbsolute(
                string $name,
                array $arguments = [],
                array $queryParameters = [],
                ?string $hash = null,
                ?string $scheme = null,
                ?string $host = null,
            ): string {
                return 'http://localhost/' . $name;
            }

            public function generateFromCurrent(
                array $replacedArguments,
                array $queryParameters = [],
                ?string $hash = null,
                ?string $fallbackRouteName = null,
            ): string {
                return '/current';
            }

            public function getUriPrefix(): string
            {
                return '';
            }

            public function setUriPrefix(string $name): void {}

            public function setDefaultArgument(string $name, bool|float|int|string|Stringable|null $value): void {}
        };
    }
}
