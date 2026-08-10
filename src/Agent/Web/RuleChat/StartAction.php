<?php

declare(strict_types=1);

namespace App\Agent\Web\RuleChat;

use App\Agent\Application\CurrentAgent;
use App\Ai\Contract\Exception\AiException;
use App\Chat\Application\AskKnowledgeBaseService;
use App\Chat\Application\ChatParams;
use App\Chat\Application\FindOrCreateThreadService;
use App\Chat\Application\RuleChatKnowledgeBaseResolver;
use App\Chat\Domain\ChatParticipant;
use App\Chat\Domain\ChatRetrievalScope;
use App\Chat\Domain\Exception\ChatUnavailable;
use App\Chat\Domain\Exception\QuestionInvalid;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Session\SessionInterface;

use function mb_strlen;
use function trim;

/**
 * POST /agent/rule-chat — validates before findOrCreate so empty posts never create a thread.
 */
final readonly class StartAction
{
    public function __construct(
        private AskKnowledgeBaseService $askService,
        private FindOrCreateThreadService $threads,
        private RuleChatKnowledgeBaseResolver $resolver,
        private CurrentAgent $currentAgent,
        private ChatParams $params,
        private Redirect $redirect,
        private FlashMessages $flash,
        private SessionInterface $session,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $question = trim(FormData::fromRequest($request)->raw('question'));
        $participant = ChatParticipant::agent($this->currentAgent->get()->adminId);

        if ($question === '') {
            $this->flash->error('Enter a question.');

            return $this->redirect->afterPost('agent.rule-chat.index');
        }
        if (mb_strlen($question) > $this->params->maxQuestionLength) {
            $this->flash->error('Your question is too long.');

            return $this->redirect->afterPost('agent.rule-chat.index');
        }

        $this->session->close();

        try {
            $knowledgeBase = $this->resolver->requireReady();
            $conversation = $this->threads->findOrCreate(
                $knowledgeBase->id(),
                $participant,
                $knowledgeBase->name(),
            );
            $this->askService->ask(
                $knowledgeBase,
                $conversation->id,
                $question,
                ChatRetrievalScope::RuleOnly,
            );
        } catch (QuestionInvalid|ChatUnavailable $e) {
            $this->flash->error($e->getMessage());
        } catch (AiException) {
            $this->flash->error('The assistant is temporarily unavailable. Please try again in a moment.');
        }

        return $this->redirect->afterPost('agent.rule-chat.index');
    }
}
