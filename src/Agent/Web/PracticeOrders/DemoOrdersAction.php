<?php

declare(strict_types=1);

namespace App\Agent\Web\PracticeOrders;

use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Web\DemoOrders\Action as OrderTestingDemoOrdersAction;

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
 * authorization boundary:
 * {@see visibleTo()} narrows this list to the signed-in agent's own imported results.
 */
final readonly class DemoOrdersAction extends OrderTestingDemoOrdersAction
{
    /**
     * One agent's own imported results, and nobody else's.
     *
     * This is the list behind **All Demo Orders**, and before this override it was the whole of
     * them — every practitioner's work on that source order, one click from an agent's own page.
     * Narrowing it here rather than in the template is deliberate: a row that is not theirs never
     * enters the response, so no later change to how a row is drawn can leak one.
     *
     * Unmatched rows go too. An order nobody can be shown to have created is not this agent's to
     * claim, which is the same answer the Practice Attempts page gives.
     *
     * @param list<DemoOrder> $orders
     *
     * @return list<DemoOrder>
     */
    protected function visibleTo(array $orders): array
    {
        return $this->visibility->onlyTheReaders($orders);
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
