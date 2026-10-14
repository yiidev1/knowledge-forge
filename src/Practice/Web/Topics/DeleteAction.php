<?php

declare(strict_types=1);

namespace App\Practice\Web\Topics;

use App\Practice\Application\TopicService;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Deletes a topic — safely, which here means the row stays and the training record stays with it.
 *
 * An assignment is a record of what somebody practised, and it exists nowhere else. So the topic
 * leaves every list and every picker, frees its name for reuse, and the orders that carry it keep
 * carrying it and keep reading correctly. Nothing cascades: no source order, demo order or attempt is
 * touched, and the foreign key on the assignment table is `RESTRICT` precisely so a real delete could
 * not happen by accident.
 *
 * The flash says how many orders kept it, so the consequence is stated rather than assumed.
 */
final readonly class DeleteAction
{
    public function __construct(
        private TopicService $topics,
        private FlashMessages $flash,
        private Redirect $redirect,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $id,
    ): ResponseInterface {
        $kept = $this->topics->delete($id);

        $this->flash->success(
            $kept === 0
                ? 'Topic deleted successfully.'
                : 'Topic deleted successfully. ' . $kept . ' order' . ($kept === 1 ? '' : 's')
                    . ' still show it, and keep it.',
        );

        return $this->redirect->afterPost('practice.topics');
    }
}
