<?php

declare(strict_types=1);

namespace App\Order58\Web\Stores;

use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;
use App\Order58\Application\EnsureStoreKnowledgeBaseService;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Enables or disables agent access to a store's knowledge base
 * (POST /admin/order58/stores/{storeId}/agent-access).
 *
 * This is the local administrator override (`agent_enabled`), kept independent of the store's source-active
 * status: disabling here hides the store from agents without deactivating it or touching the Order58
 * mirror, and a subsequent sync never overrides the choice. The desired state is submitted explicitly, so a
 * double submission is idempotent.
 */
final readonly class ToggleAgentAccessAction
{
    public function __construct(
        private KnowledgeBaseSourceRepositoryInterface $knowledgeBases,
        private ClockInterface $clock,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(#[RouteArgument] int $storeId, ServerRequestInterface $request): ResponseInterface
    {
        $enabled = FormData::fromRequest($request)->string('enabled') === '1';

        $knowledgeBaseId = $this->knowledgeBases->findIdBySource(EnsureStoreKnowledgeBaseService::SOURCE, $storeId);
        if ($knowledgeBaseId === null) {
            $this->flash->error('That store has no knowledge base yet. Run Sync Stores first.');

            return $this->redirect->afterPost('order58.stores');
        }

        $this->knowledgeBases->setAgentEnabled($knowledgeBaseId, $enabled, $this->clock->now());
        $this->flash->success($enabled ? 'Agent access enabled for this store.' : 'Agent access disabled for this store.');

        return $this->redirect->afterPost('order58.stores');
    }
}
