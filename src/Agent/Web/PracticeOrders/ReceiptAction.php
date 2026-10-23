<?php

declare(strict_types=1);

namespace App\Agent\Web\PracticeOrders;

use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Web\Receipt\Action as OrderTestingReceiptAction;

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
 * {@see admits()} narrows this page to the signed-in agent's own receipts.
 */
final readonly class ReceiptAction extends OrderTestingReceiptAction
{
    /**
     * One agent's own receipt, and nobody else's.
     *
     * Same reasoning as the comparison beside it: the demo order's id is in the path, so the page
     * is reachable by typing one, and a receipt is the whole of somebody's order — the customer's
     * name, address and telephone number included. A row that is not this agent's answers exactly
     * as a row that does not exist.
     */
    protected function admits(DemoOrder $demo): bool
    {
        return $this->visibility->belongsToReader($demo);
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
