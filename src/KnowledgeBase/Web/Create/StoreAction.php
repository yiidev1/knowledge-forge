<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Web\Create;

use App\KnowledgeBase\Application\CreateKnowledgeBaseService;
use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Handles the new-knowledge-base submission (POST /knowledge-bases).
 *
 * On success it redirects to the new base's detail page (post/redirect/get). On a validation error it
 * re-renders the form with the submitted values and inline messages, which is the conventional,
 * refresh-safe way to report field errors.
 */
final readonly class StoreAction
{
    public function __construct(
        private CreateKnowledgeBaseService $createService,
        private KnowledgeBaseRepositoryInterface $repository,
        private WebViewRenderer $viewRenderer,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $form = FormData::fromRequest($request);
        $name = $form->string('name');
        $description = $form->nullableString('description');
        $instructions = $form->nullableString('system_instructions');

        try {
            $id = $this->createService->create($name, $description, $instructions);
        } catch (ValidationException $e) {
            return $this->viewRenderer
                ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
                ->render(__DIR__ . '/template', [
                    'values' => ['name' => $name, 'description' => (string) $description, 'system_instructions' => (string) $instructions],
                    'errors' => $e->errors(),
                ]);
        }

        // Re-load to obtain the generated slug for the redirect target.
        $knowledgeBase = $this->repository->findById($id);
        $this->flash->success('Knowledge base created and queued for provisioning.');

        return $this->redirect->afterPost('kb.show', ['slug' => $knowledgeBase?->slug() ?? '']);
    }
}
