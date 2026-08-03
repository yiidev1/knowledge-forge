<?php

declare(strict_types=1);

namespace App\Document\Web\ResetOrder58;

use App\Document\Application\Order58\ResetOrder58DocumentService;
use App\Document\Domain\Exception\Order58MirrorMissing;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * POST …/documents/{id}/reset-order58 — discard local override; restore from mirror.
 */
final readonly class Action
{
    public function __construct(
        private ResetOrder58DocumentService $reset,
        private KnowledgeBaseFinder $finder,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(#[RouteArgument] string $slug, #[RouteArgument] int $documentId): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);

        try {
            $this->reset->reset($documentId, $knowledgeBase->id());
            $this->flash->success('Document reset to the latest Order58 version and queued for re-indexing.');
        } catch (Order58MirrorMissing $e) {
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }
}
