<?php

declare(strict_types=1);

namespace App\Practice\Web\Topics;

use App\Practice\Application\TopicService;
use App\Practice\Domain\TopicRepositoryInterface;
use App\Practice\Domain\TopicStatus;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Renames a topic, changes its description, and sets whether it is offered.
 *
 * Those three, and no more. **Deleting is not reachable from this form** — it has its own endpoint and
 * its own confirmation, so a rename can never retire a topic by accident. Active and Inactive are a
 * different matter: the field shows the topic's real current state and saving it is the same decision
 * the row's own Activate/Deactivate button makes, through the same service method.
 *
 * Assignments point at the id, so a rename is invisible to every order carrying the topic — which is
 * the whole reason the name is not copied onto them.
 */
final readonly class UpdateAction
{
    public function __construct(
        private TopicService $topics,
        private TopicRepositoryInterface $repository,
        private TopicsPage $page,
        private FlashMessages $flash,
        private Redirect $redirect,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        #[RouteArgument]
        int $id,
    ): ResponseInterface {
        $form = FormData::fromRequest($request);
        $name = $form->string('name');
        $description = $form->nullableString('description');
        $status = $form->string('status');
        $store = $form->string('store_source_id');

        try {
            // Only the two values the form offers count as a decision. Anything else — a field
            // stripped by a proxy, a crafted body naming the stored delete value — leaves the status
            // exactly as it is rather than being read as "deactivate".
            $this->topics->update(
                $id,
                $name,
                $description,
                match ($status) {
                    TopicStatus::Active->value => true,
                    TopicStatus::Inactive->value => false,
                    default => null,
                },
                $store === '' ? null : (int) $store,
                // Only a body that actually carried the field may move the scope. A request without
                // it leaves the topic where it is, rather than being read as "make this global" —
                // the same rule the status above follows, and for the same reason.
                $form->has('store_source_id'),
            );
        } catch (ValidationException $e) {
            return $this->page->render(
                $request,
                editing: $this->repository->find($id),
                values: [
                    'name' => $name,
                    'description' => (string) $description,
                    'status' => $status,
                    'store_source_id' => $store,
                ],
                errors: $e->errors(),
            );
        }

        $this->flash->success('Topic updated.');

        return $this->redirect->afterPost('practice.topics');
    }
}
