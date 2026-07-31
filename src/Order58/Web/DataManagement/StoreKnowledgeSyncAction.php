<?php

declare(strict_types=1);

namespace App\Order58\Web\DataManagement;

use App\Auth\Application\CurrentAdmin;
use App\Order58\Application\EnqueueSyncService;
use App\Order58\Domain\Order58SyncType;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Secondary action: enqueues a scoped sync of one store's knowledge (POST /admin/order58/stores/{storeId}/
 * sync-knowledge), using `GET /knowledge?store_id=`. Same `_sync_hash` and indexing rules as the full
 * Knowledge sync, and independent per store. Not one of the three primary buttons.
 */
final readonly class StoreKnowledgeSyncAction
{
    public function __construct(
        private EnqueueSyncService $enqueue,
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $storeId,
    ): ResponseInterface {
        if ($this->enqueue->enqueue(Order58SyncType::KnowledgeStore, $storeId, $this->currentAdmin->get()->id())) {
            $this->flash->success('Knowledge sync queued for store ' . $storeId . '.');
        } else {
            $this->flash->error('A knowledge sync for store ' . $storeId . ' is already queued or running.');
        }

        return $this->redirect->afterPost('order58.index');
    }
}
