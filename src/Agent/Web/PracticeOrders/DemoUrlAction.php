<?php

declare(strict_types=1);

namespace App\Agent\Web\PracticeOrders;

use App\OrderTesting\Web\DemoUrl\Action as OrderTestingDemoUrlAction;

/**
 * The same tracked endpoint, answering with the agent surface's own addresses.
 *
 * ## Why this class exists
 *
 * The endpoint itself is right for an agent and is deliberately shared: it is the only place an
 * attempt is recorded, and a second copy would be a second attribution rule to keep honest. What was
 * wrong was where it sent a browser afterwards. Both of its non-script answers named Manage Orders —
 * a refusal redirected there, and the continue page linked back to it — and an agent's gate refuses
 * that address, so a failed or script-less Practice Order press landed them somewhere unrelated to
 * what they were doing rather than back on their own page.
 *
 * So this changes the map of addresses and the shell, and nothing else. The attempt, the actor it is
 * attributed to, the URL allow-list and the refusal messages are the ones Order Testing already uses.
 */
final readonly class DemoUrlAction extends OrderTestingDemoUrlAction
{
    protected function surfaceRoutes(): array
    {
        return [
            'store' => 'agent.practice-orders.store',
            'storeLabel' => 'Practice Orders',
            'page' => 'agent.practice-orders',
        ];
    }

    protected function layoutPath(): string
    {
        return '@src/Agent/Web/Layout/layout.php';
    }
}
