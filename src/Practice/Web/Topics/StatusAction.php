<?php

declare(strict_types=1);

namespace App\Practice\Web\Topics;

use App\Practice\Application\TopicService;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Activates or deactivates a topic.
 *
 * Deactivating is a pause, not a delete: the topic keeps every assignment it has and can be made
 * active again. What it stops is being offered for new ones.
 */
final readonly class StatusAction
{
    public function __construct(
        private TopicService $topics,
        private FlashMessages $flash,
        private Redirect $redirect,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        #[RouteArgument]
        int $id,
    ): ResponseInterface {
        $activate = FormData::fromRequest($request)->string('status') === 'ACTIVE';

        try {
            $activate ? $this->topics->activate($id) : $this->topics->deactivate($id);
            $this->flash->success($activate ? 'Topic activated.' : 'Topic deactivated.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $message) {
                $this->flash->error($message);
            }
        }

        return $this->redirect->afterPost('practice.topics');
    }
}
