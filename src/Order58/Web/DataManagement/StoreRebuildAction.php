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
 * Secondary action: rebuilds one store's generated documents from the local mirror (POST
 * /admin/order58/stores/{storeId}/rebuild), forcing regeneration and re-indexing without an API call.
 */
final readonly class StoreRebuildAction
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
        if ($this->enqueue->enqueue(Order58SyncType::RebuildStore, $storeId, $this->currentAdmin->get()->id())) {
            $this->flash->success('Rebuild queued for store ' . $storeId . '.');
        } else {
            $this->flash->error('A rebuild for store ' . $storeId . ' is already queued or running.');
        }

        return $this->redirect->afterPost('order58.index');
    }
}
