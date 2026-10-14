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

use function is_array;
use function is_scalar;

/**
 * Puts one topic on several source orders at once.
 *
 * ## What arrives, and what is trusted of it
 *
 * A list of checkbox values. None of it is trusted: the service de-duplicates, bounds the size, and
 * refuses the whole request if any id is not an order of **this** store. Nothing is written unless
 * every id passes, because "14 of 15 saved" leaves an operator with no way to find the fifteenth.
 *
 * ## Select-all means this page
 *
 * The checkboxes are the rows that were rendered, so a bulk assignment can only reach what the
 * operator could see. There is no server-side "every order matching the current filter" selection, and
 * the page says so rather than implying one.
 */
final readonly class BulkAssignAction
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
        $topicId = (int) $form->string('topic_id');

        /** @var mixed $posted */
        $posted = $form->rawValue('source_order_ids');
        $orderIds = [];

        if (is_array($posted)) {
            foreach ($posted as $value) {
                if (is_scalar($value)) {
                    $orderIds[] = (int) $value;
                }
            }
        }

        try {
            $written = $this->assign->assignMany($sourceId, $orderIds, $topicId, $this->actor->assignedBy());

            $this->flash->success(
                'Topic assigned to ' . $written . ' order' . ($written === 1 ? '' : 's') . '.',
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $message) {
                $this->flash->error($message);
            }
        }

        // Back to the filtered list the selection was made in — see {@see FilterReturn}.
        return $this->redirect->afterPost(
            'practice-orders.store',
            ['sourceId' => $sourceId] + FilterReturn::arguments($form->string('filter_topic')),
        );
    }
}
