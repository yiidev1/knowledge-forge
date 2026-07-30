<?php

declare(strict_types=1);

namespace App\Ai\Web\Usage;

use App\Ai\Application\Usage\CollectUsageSnapshotService;
use App\Ai\Application\Usage\SyncAttemptMarkerInterface;
use App\Ai\Application\Usage\SyncProblem;
use App\Ai\Application\Usage\UsageParams;
use App\Ai\Application\Usage\UsageSnapshotStoreInterface;
use App\Ai\Infrastructure\Usage\UsageSnapshotWriteFailed;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Session\SessionInterface;

use function count;

/**
 * Refreshes the cached snapshot from OpenAI (POST /admin/openai-usage/sync).
 *
 * Read-only against the provider: it lists vector stores and their files and writes the result to the
 * local cache. It creates, attaches, detaches, re-indexes and deletes nothing.
 *
 * Three protections worth naming, because each guards a distinct failure:
 *
 * - **Throttle.** Recorded BEFORE the work starts, so a sync that then fails still counts as an attempt.
 *   Throttling on the last *successful* snapshot instead would leave a persistently failing sync
 *   completely unthrottled — exactly when repeated attempts are least useful.
 * - **Session release.** The PHP session lock is dropped once authentication and CSRF have had what
 *   they need, so a slow sweep does not block every other tab in the same browser.
 * - **Never overwrite good data with a failure.** A sync that could not reach the inventory leaves the
 *   previous snapshot in place; the page keeps showing it, marked stale.
 */
final readonly class SyncAction
{
    public function __construct(
        private CollectUsageSnapshotService $collector,
        private UsageSnapshotStoreInterface $snapshots,
        private SyncAttemptMarkerInterface $attempts,
        private ClockInterface $clock,
        private Redirect $redirect,
        private FlashMessages $flash,
        private SessionInterface $session,
        private UsageParams $params,
    ) {}

    public function __invoke(): ResponseInterface
    {
        $now = $this->clock->now();
        $lastAttempt = $this->attempts->lastAttemptAt();

        if ($lastAttempt !== null && $now->getTimestamp() - $lastAttempt->getTimestamp() < $this->params->throttleSeconds) {
            $this->flash->warning('A sync was just run. Please wait a few seconds before syncing again.');

            return $this->redirect->afterPost('ai.usage.index');
        }

        // Counted before the work, so a failure still throttles the next attempt.
        $this->attempts->markAttempt($now);

        // Authentication and CSRF validation are already done by the time an action runs, so nothing
        // below needs the session. Releasing it here keeps a second tab responsive for the duration of
        // the sweep; the flash write afterwards reopens it transparently.
        $this->session->close();

        $snapshot = $this->collector->collect();

        $inventoryFailed = false;
        foreach ($snapshot->problems as $problem) {
            if ($problem->source === SyncProblem::SOURCE_VECTOR_STORES) {
                $inventoryFailed = true;
            }
        }

        if ($inventoryFailed) {
            // Keep whatever we had. An empty snapshot would replace real figures with zeroes, which
            // reads as "you have no vector stores" rather than "we could not ask".
            $this->flash->error(
                'Could not reach OpenAI to list vector stores. Any previously synced data is still shown below.',
            );

            return $this->redirect->afterPost('ai.usage.index');
        }

        try {
            $this->snapshots->save($snapshot);
        } catch (UsageSnapshotWriteFailed $e) {
            $this->flash->error($e->getMessage());

            return $this->redirect->afterPost('ai.usage.index');
        }

        $this->flashOutcome($snapshot->stores === [], count($snapshot->problems), $snapshot->truncated);

        return $this->redirect->afterPost('ai.usage.index');
    }

    private function flashOutcome(bool $empty, int $problemCount, bool $truncated): void
    {
        if ($problemCount > 0) {
            $this->flash->warning(
                'Synced, but some details could not be fetched. See the notes below for what is missing.',
            );

            return;
        }

        if ($truncated) {
            $this->flash->warning('Synced. The sweep hit its safety limits, so the figures below are a partial view.');

            return;
        }

        $this->flash->success($empty ? 'Synced. No vector stores were found.' : 'Synced with OpenAI.');
    }
}
