<?php

declare(strict_types=1);

namespace App\Order58\Application\Sync;

use App\Order58\Application\Mapper\AgentMapper;
use App\Order58\Application\Order58SyncParams;
use App\Order58\Contract\Dto\Order58Agent;
use App\Order58\Contract\Exception\Order58Exception;
use App\Order58\Contract\Order58ClientInterface;
use App\Order58\Domain\Order58AgentRepositoryInterface;
use App\Order58\Domain\SyncProgress;
use App\Order58\Domain\SyncRun;
use App\Order58\Domain\SyncRunRepositoryInterface;
use DateTimeImmutable;

/**
 * Full paginated scan of Order58 agents. Mirrors safe fields only (never a credential), skips unchanged
 * records by `_sync_hash`, and after the final page marks agents that vanished from the source inactive.
 * Produces no documents and never calls OpenAI. `account_id` is mirrored as profile data only.
 */
final readonly class AgentsSyncHandler implements Order58SyncHandlerInterface
{
    public function __construct(
        private Order58ClientInterface $client,
        private Order58AgentRepositoryInterface $agents,
        private AgentMapper $mapper,
        private SyncRunRepositoryInterface $runs,
        private Order58SyncParams $params,
    ) {}

    public function handle(SyncRun $run, DateTimeImmutable $now): SyncOutcome
    {
        $progress = $run->progress();
        $runId = $run->id();
        $pagesThisRun = 0;

        while (true) {
            try {
                $page = $this->client->listAgents($progress->nextPage, $this->params->pageSize);
            } catch (Order58Exception $e) {
                return $e->isTransient()
                    ? SyncOutcome::transient($progress, $e->details()->code, $e->getMessage())
                    : SyncOutcome::failed($progress, $e->details()->code, $e->getMessage());
            }

            foreach ($page->items as $agent) {
                $this->processAgent($agent, $runId, $progress, $now);
            }

            $progress->pagesProcessed++;
            $progress->nextPage++;
            $pagesThisRun++;
            $this->runs->saveProgress($runId, $progress, $now);

            if (!$page->pagination->hasMore()) {
                break;
            }
            if ($pagesThisRun >= $this->params->pagesPerRun) {
                return SyncOutcome::partial($progress);
            }
        }

        $progress->deactivated += $this->agents->deactivateNotSeen($runId, $now);

        return SyncOutcome::completed($progress);
    }

    private function processAgent(Order58Agent $agent, int $runId, SyncProgress $progress, DateTimeImmutable $now): void
    {
        $existingHash = $this->agents->findSyncHash($agent->adminId);
        if ($existingHash === $agent->syncHash) {
            $this->agents->markSeen($agent->adminId, $runId, $now);
            $progress->unchanged++;

            return;
        }

        $this->agents->save($this->mapper->toMirror($agent), $runId, $now);
        if ($existingHash === null) {
            $progress->created++;
        } else {
            $progress->updated++;
        }
    }
}
