<?php

declare(strict_types=1);

namespace App\Practice\Web\Assign;

use App\Practice\Application\AssignTopicService;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function is_numeric;

/**
 * Puts a topic on one source order, or takes it off.
 *
 * The store id comes from the route and the order id from the body, and the service proves the two
 * belong together before anything is written — a posted order id is not trusted to be this store's.
 */
final readonly class AssignAction
{
    public function __construct(
        private AssignTopicService $assign,
        private ActorFromRequest $actor,
        private FlashMessages $flash,
        private Redirect $redirect,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        #[RouteArgument]
        int $sourceId,
    ): ResponseInterface {
        $form = FormData::fromRequest($request);
        $orderId = $form->string('source_order_id');
        $topicId = $form->string('topic_id');

        try {
            if (!is_numeric($orderId)) {
                throw ValidationException::forField('orders', 'That order could not be identified.');
            }

            // An empty topic is how the dialog says "no topic", which is a real choice rather than a
            // failed one.
            if ($topicId === '') {
                $this->assign->unassign($sourceId, (int) $orderId, $this->actor->assignedBy());
                $this->flash->success('Topic removed from order #' . (int) $orderId . '.');
            } else {
                $this->assign->assign($sourceId, (int) $orderId, (int) $topicId, $this->actor->assignedBy());
                $this->flash->success('Topic assigned to order #' . (int) $orderId . '.');
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $message) {
                $this->flash->error($message);
            }
        }

        // Back to the list the assignment was made from, filter and all: an argument the route pattern
        // does not use becomes a query parameter, so this is `?topic=…` without hand-built URLs.
        return $this->redirect->afterPost(
            'practice-orders.store',
            ['sourceId' => $sourceId] + FilterReturn::arguments($form->string('filter_topic')),
        );
    }
}
