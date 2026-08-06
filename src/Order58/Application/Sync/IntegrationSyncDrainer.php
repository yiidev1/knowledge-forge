<?php

declare(strict_types=1);

namespace App\Order58\Application\Sync;

use App\Order58\Application\Order58SyncParams;
use App\Order58\Client\Order58Credentials;
use App\Order58\Contract\Exception\Order58Exception;
use App\Order58\Contract\Order58ClientInterface;
use App\Order58\Domain\Order58SyncType;
use App\Order58\Domain\SyncRun;
use App\Order58\Domain\SyncRunRepositoryInterface;
use App\Order58\Domain\SyncRunStatus;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Worker\Application\DrainerInterface;
use App\Worker\Application\DrainResult;
use DateTimeImmutable;
use Throwable;

use function min;

/**
 * The worker drainer that runs Order58 synchronization. Claims one eligible run at a time and dispatches
 * it to the matching handler; a health run is answered inline by a single bounded probe. Overlapping cron
 * invocations are already serialized by the worker's flock, so the atomic claim here is defence in depth.
 *
 * All Order58 and OpenAI-adjacent work stays here in the background — never in a web request.
 */
final readonly class IntegrationSyncDrainer implements DrainerInterface
{
    private const STUCK_AFTER = '-15 minutes';

    public function __construct(
        private SyncRunRepositoryInterface $runs,
        private Order58ClientInterface $client,
        private Order58Credentials $credentials,
        private StoresSyncHandler $storesHandler,
        private AgentsSyncHandler $agentsHandler,
        private KnowledgeSyncHandler $knowledgeHandler,
        private RulesSyncHandler $rulesHandler,
        private RebuildStoreHandler $rebuildHandler,
        private Order58SyncParams $params,
        private ClockInterface $clock,
        private SecretRedactor $redactor,
    ) {}

    public function name(): string
    {
        return 'order58-sync';
    }

    public function recover(): void
    {
        $now = $this->clock->now();
        $this->runs->recoverStuck($now->modify(self::STUCK_AFTER), $now);
    }

    public function drain(int $limit): DrainResult
    {
        // Nothing to do until the Integration API is configured; the drainer simply stays quiet.
        if (!$this->credentials->isComplete()) {
            return DrainResult::nothing();
        }

        $now = $this->clock->now();
        $processed = 0;
        $failed = 0;

        foreach ($this->runs->findClaimable($limit, $now) as $run) {
            if (!$this->runs->claim($run->id(), $now)) {
                continue;
            }
            $claimed = $this->runs->findById($run->id());
            if ($claimed === null) {
                continue;
            }

            $result = $this->process($claimed, $now);
            $processed += $result[0];
            $failed += $result[1];
        }

        return new DrainResult($processed, $failed);
    }

    /**
     * @return array{0: int, 1: int} [processed, failed]
     */
    private function process(SyncRun $run, DateTimeImmutable $now): array
    {
        if ($run->type() === Order58SyncType::Health) {
            return $this->processHealth($run, $now);
        }

        $handler = match ($run->type()) {
            Order58SyncType::Stores => $this->storesHandler,
            Order58SyncType::Agents => $this->agentsHandler,
            Order58SyncType::Knowledge, Order58SyncType::KnowledgeStore => $this->knowledgeHandler,
            Order58SyncType::Rules => $this->rulesHandler,
            Order58SyncType::RebuildStore => $this->rebuildHandler,
            default => null,
        };

        if ($handler === null) {
            $this->runs->finish($run->id(), SyncRunStatus::Failed, $run->progress(), 'unsupported_type', 'Unsupported sync type.', $now);

            return [0, 1];
        }

        try {
            $outcome = $handler->handle($run, $now);
        } catch (Throwable $e) {
            $this->runs->finish(
                $run->id(),
                SyncRunStatus::Failed,
                $run->progress(),
                'sync_error',
                $this->safe($e->getMessage()),
                $now,
            );

            return [0, 1];
        }

        return $this->persist($run, $outcome, $now);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function processHealth(SyncRun $run, DateTimeImmutable $now): array
    {
        try {
            $health = $this->client->health();
        } catch (Order58Exception $e) {
            $this->runs->finish($run->id(), SyncRunStatus::Failed, $run->progress(), $e->details()->code, $this->safe($e->getMessage()), $now);

            return [0, 1];
        }

        if ($health->ok) {
            $this->runs->finish(
                $run->id(),
                SyncRunStatus::Completed,
                $run->progress(),
                null,
                'API version ' . ($health->apiVersion ?? 'unknown'),
                $now,
            );

            return [1, 0];
        }

        $this->runs->finish($run->id(), SyncRunStatus::Failed, $run->progress(), 'health_failed', $this->safe($health->message ?? 'Health check failed.'), $now);

        return [0, 1];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function persist(SyncRun $run, SyncOutcome $outcome, DateTimeImmutable $now): array
    {
        if ($outcome->terminalStatus !== null) {
            $this->runs->finish(
                $run->id(),
                $outcome->terminalStatus,
                $outcome->progress,
                $outcome->errorCode,
                $this->safe($outcome->errorMessage),
                $now,
            );

            return $outcome->terminalStatus === SyncRunStatus::Failed ? [0, 1] : [1, 0];
        }

        if ($outcome->transientFailure) {
            if ($run->attempts() + 1 >= $this->params->maxAttempts) {
                $this->runs->finish($run->id(), SyncRunStatus::Failed, $outcome->progress, $outcome->errorCode, $this->safe($outcome->errorMessage), $now);

                return [0, 1];
            }

            $delaySeconds = min(3600, 60 * (2 ** $run->attempts()));
            $this->runs->requeue(
                $run->id(),
                $now->modify('+' . $delaySeconds . ' seconds'),
                $outcome->errorCode,
                $this->safe($outcome->errorMessage),
                $now,
            );

            return [0, 0];
        }

        // Partial: the handler already persisted progress; the run stays running and resumes next pass.
        return [1, 0];
    }

    private function safe(?string $message): ?string
    {
        return $message === null ? null : $this->redactor->redactAndTruncate($message, 1000);
    }
}
