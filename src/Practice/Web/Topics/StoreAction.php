<?php

declare(strict_types=1);

namespace App\Practice\Web\Topics;

use App\Auth\Application\CurrentAdmin;
use App\Practice\Application\TopicService;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Creates a topic.
 *
 * On success: a flash and a 303, so a refresh cannot create a second one. On failure: the same page
 * again with the dialog open, the typed values back in it and the message under its field — see
 * {@see TopicsPage}.
 */
final readonly class StoreAction
{
    public function __construct(
        private TopicService $topics,
        private TopicsPage $page,
        private FlashMessages $flash,
        private Redirect $redirect,
        private CurrentAdmin $currentAdmin,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $form = FormData::fromRequest($request);
        $name = $form->string('name');
        $description = $form->nullableString('description');
        $active = $form->string('status') !== 'INACTIVE';

        try {
            $this->topics->create($name, $description, $active, $this->currentAdmin->getOrNull()?->id());
        } catch (ValidationException $e) {
            return $this->page->render(
                $request,
                creating: true,
                values: ['name' => $name, 'description' => (string) $description, 'status' => $active ? 'ACTIVE' : 'INACTIVE'],
                errors: $e->errors(),
            );
        }

        $this->flash->success('Topic "' . $name . '" created.');

        return $this->redirect->afterPost('practice.topics');
    }
}
