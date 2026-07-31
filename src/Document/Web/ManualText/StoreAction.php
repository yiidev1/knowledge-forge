<?php

declare(strict_types=1);

namespace App\Document\Web\ManualText;

use App\Document\Application\Text\ManualTextService;
use App\Document\Domain\Exception\UploadException;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Creates a manual-text document (POST /knowledge-bases/{slug}/manual-text). Returns immediately — the
 * worker indexes it. Validation/duplicate/limit failures carry a message already safe to show.
 */
final readonly class StoreAction
{
    public function __construct(
        private ManualTextService $manualText,
        private KnowledgeBaseFinder $finder,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, ServerRequestInterface $request): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $form = FormData::fromRequest($request);

        try {
            $this->manualText->create($knowledgeBase->id(), $form->string('title'), $form->raw('content'));
            $this->flash->success('Manual text saved and queued for indexing.');

            return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
        } catch (UploadException $e) {
            $this->flash->error($e->getMessage());

            return $this->redirect->afterPost('kb.manual-text.create', ['slug' => $slug]);
        }
    }
}
