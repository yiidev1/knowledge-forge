<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Web\SyncKnowledge;

use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\KnowledgeBase\Web\KnowledgeBaseFinder;
use App\Order58\Application\EnqueueSyncService;
use App\Order58\Domain\Order58SyncType;
use App\Auth\Application\CurrentAdmin;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Queues an Order58 knowledge sync for the store behind this knowledge base
 * (POST /knowledge-bases/{slug}/sync-order58-knowledge), then returns to the Manage KB page.
 *
 * It reuses the existing per-store sync backend ({@see EnqueueSyncService} with {@see Order58SyncType::KnowledgeStore}),
 * so the work runs in the background and the existing coalescing prevents a duplicate pending/running job. No
 * sync or indexing logic is duplicated here.
 */
final readonly class Action
{
    public function __construct(
        private KnowledgeBaseFinder $finder,
        private KnowledgeBaseSourceRepositoryInterface $source,
        private EnqueueSyncService $enqueue,
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(#[RouteArgument] string $slug): ResponseInterface
    {
        $knowledgeBase = $this->finder->bySlug($slug);
        $storeId = $this->source->findOrder58StoreId($knowledgeBase->id());

        if ($storeId === null) {
            $this->flash->error('This knowledge base is not linked to an Order58 store.');

            return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
        }

        if ($this->enqueue->enqueue(Order58SyncType::KnowledgeStore, $storeId, $this->currentAdmin->get()->id())) {
            $this->flash->success('Knowledge sync has been queued.');
        } else {
            $this->flash->error('A knowledge sync for this store is already queued or running.');
        }

        return $this->redirect->afterPost('kb.show', ['slug' => $slug]);
    }
}
