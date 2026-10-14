<?php

declare(strict_types=1);

namespace App\Agent\Web\PracticeOrders;

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
 * So this changes two things and nothing else: the map of addresses, and the layout. The action, the
 * repositories, the comparison and the template are the ones Order Testing already uses.
 */
final readonly class CompareAction extends OrderTestingCompareAction
{
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
