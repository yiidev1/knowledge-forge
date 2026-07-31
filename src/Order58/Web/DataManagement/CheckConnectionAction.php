<?php

declare(strict_types=1);

namespace App\Order58\Web\DataManagement;

use App\Auth\Application\CurrentAdmin;
use App\Order58\Application\EnqueueSyncService;
use App\Order58\Domain\Order58SyncType;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;

/**
 * Enqueues a bounded health probe (POST /admin/order58/check). The page itself renders the last cached
 * result; this action asks the worker to refresh it, so no Integration API call happens in the request.
 */
final readonly class CheckConnectionAction
{
    public function __construct(
        private EnqueueSyncService $enqueue,
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(): ResponseInterface
    {
        if ($this->enqueue->enqueue(Order58SyncType::Health, null, $this->currentAdmin->get()->id())) {
            $this->flash->success('Connection check queued.');
        } else {
            $this->flash->error('A connection check is already queued or running.');
        }

        return $this->redirect->afterPost('order58.index');
    }
}
