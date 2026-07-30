<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Web\Edit;

use App\KnowledgeBase\Application\UpdateKnowledgeBaseService;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Applies edits to a knowledge base (POST /knowledge-bases/{slug}). The slug is never changed.
 */
final readonly class UpdateAction
{
    public function __construct(
        private UpdateKnowledgeBaseService $updateService,
        private KnowledgeBaseFinder $finder,
        private WebViewRenderer $viewRenderer,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, ServerRequestInterface $request): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);

        $form = FormData::fromRequest($request);
        $name = $form->string('name');
        $description = $form->nullableString('description');
        $instructions = $form->nullableString('system_instructions');

        try {
            $this->updateService->update($knowledgeBase->id(), $name, $description, $instructions);
        } catch (ValidationException $e) {
            return $this->viewRenderer
                ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
                ->render(__DIR__ . '/template', [
                    'knowledgeBase' => $knowledgeBase,
                    'values' => ['name' => $name, 'description' => (string) $description, 'system_instructions' => (string) $instructions],
                    'errors' => $e->errors(),
                ]);
        }

        $this->flash->success('Knowledge base updated.');

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }
}
