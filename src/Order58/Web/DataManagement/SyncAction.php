<?php

declare(strict_types=1);

namespace App\Order58\Web\DataManagement;

use App\Auth\Application\CurrentAdmin;
use App\Order58\Application\EnqueueSyncService;
use App\Order58\Domain\Order58SyncType;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Enqueues one of the three independent primary syncs (POST /admin/order58/sync).
 *
 * Returns immediately — no Order58 or OpenAI work happens in the request. If an active run of the same
 * operation already exists, the enqueue is coalesced and the administrator is told it is already running.
 */
final readonly class SyncAction
{
    public function __construct(
        private EnqueueSyncService $enqueue,
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $type = match (FormData::fromRequest($request)->string('operation')) {
            'stores' => Order58SyncType::Stores,
            'knowledge' => Order58SyncType::Knowledge,
            'agents' => Order58SyncType::Agents,
            'rules' => Order58SyncType::Rules,
            default => null,
        };

        if ($type === null) {
            $this->flash->error('Unknown synchronization operation.');

            return $this->redirect->afterPost('order58.index');
        }

        if ($this->enqueue->enqueue($type, null, $this->currentAdmin->get()->id())) {
            $this->flash->success($type->label() . ' queued. The worker will process it shortly.');
        } else {
            $this->flash->error($type->label() . ' is already queued or running.');
        }

        return $this->redirect->afterPost('order58.index');
    }
}
