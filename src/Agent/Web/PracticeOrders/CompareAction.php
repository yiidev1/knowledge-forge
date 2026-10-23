<?php

declare(strict_types=1);

namespace App\Agent\Web\PracticeOrders;

use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Domain\TestAttempt;
use App\OrderTesting\Web\Compare\Action as OrderTestingCompareAction;

/**
 * The same page, at the agent's own addresses and inside the agent's own shell.
 *
 * ## Why this class exists
 *
 * The page itself is right for an agent: it reads demo orders and compares them with the original, and
 * reading is the whole of what this surface does. What was wrong was everything *around* the content —
 * the breadcrumbs, the back links and, on the list, the Compare link all named Order Testing addresses,
 * which an agent's gate refuses. The page rendered and its links went nowhere.
 *
 * So this changes the map of addresses and the layout — the action, the repositories, the comparison
 * and the template are the ones Order Testing already uses — and one thing more, which is an
 * authorization boundary: {@see admits()} narrows this page to the signed-in agent's own work.
 */
final readonly class CompareAction extends OrderTestingCompareAction
{
    /**
     * One agent's own comparison, and nobody else's.
     *
     * The demo order's id is in the path, so this page is reachable by typing one. On a management
     * surface that is right — a trainer compares practitioners. Here it is not: the row is checked
     * against the signed-in agent before any of it is read, and a row that is not theirs answers
     * exactly as a row that does not exist.
     *
     * The attempt the page already resolved is not trusted for this. It is resolved again inside
     * the gate, from the demo order, so the question is asked of the database rather than of a
     * value that passed through a template key.
     */
    protected function admits(DemoOrder $demo, ?TestAttempt $attempt): bool
    {
        return $this->visibility->belongsToReader($demo);
    }

    protected function canSyncItems(): bool
    {
        return false;
    }

    protected function surfaceRoutes(): array
    {
        return [
            'page' => 'agent.practice-orders',
            'pageLabel' => 'Practice Orders',
            'store' => 'agent.practice-orders.store',
            'demoOrders' => 'agent.practice-orders.demo-orders',
            'demoCompare' => 'agent.practice-orders.demo-compare',
            'demoReceipt' => 'agent.practice-orders.demo-receipt',
        ];
    }

    protected function layoutPath(): string
    {
        return '@src/Agent/Web/Layout/layout.php';
    }
}
