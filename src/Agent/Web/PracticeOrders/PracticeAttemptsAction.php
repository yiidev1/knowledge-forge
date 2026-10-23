<?php

declare(strict_types=1);

namespace App\Agent\Web\PracticeOrders;

use App\OrderTesting\Web\PracticeAttempts\Action as OrderTestingPracticeAttemptsAction;

/**
 * The agent's own practice history, and only ever their own.
 *
 * ## Two things change, and one of them is an authorization boundary
 *
 * The addresses and the shell change for the same reason every other agent subclass changes them:
 * Order Testing's routes are refused by this realm's gate, so a page drawn with them renders links
 * that go nowhere.
 *
 * {@see actorFilter()} is different in kind. It is pushed into the repository query, so an agent's
 * history is fetched narrowed — somebody else's attempts never enter the response at all, and a
 * template that rendered the list differently could not leak them. Filtering after the fact would
 * have been one forgotten `continue` away from showing a colleague's scores.
 *
 * Unmatched demo orders are not offered here either. An order nobody can be shown to have created is
 * not this agent's to claim, and presenting it under their heading would be exactly the invented
 * attribution the importer refuses to make.
 */
final readonly class PracticeAttemptsAction extends OrderTestingPracticeAttemptsAction
{
    /*
     * No constructor of its own.
     *
     * It used to restate the parent's dependency list in order to pass them straight through, which
     * meant every change to that list broke here — and the breakage was the whole value, since the
     * alternative is a surface quietly constructed with the wrong arguments. Inheriting it removes
     * the restatement and the breakage together: this class adds no dependency, so it needs no
     * constructor, and `$actor` is protected on the parent for exactly this reason.
     */

    protected function actorFilter(): array
    {
        return [$this->actor->initiatorType(), $this->actor->initiatorId()];
    }

    /** Practising again is the whole point of this surface, and the endpoint is mounted for it. */
    protected function canRetry(): bool
    {
        return true;
    }

    protected function surfaceRoutes(): array
    {
        return [
            'page' => 'agent.practice-orders',
            'pageLabel' => 'Practice Orders',
            'store' => 'agent.practice-orders.store',
            'self' => 'agent.practice-orders.demo-orders',
            'allDemoOrders' => 'agent.practice-orders.demo-orders.all',
            'demoCompare' => 'agent.practice-orders.demo-compare',
            'demoUrl' => 'agent.practice-orders.demo-url',
        ];
    }

    protected function layoutPath(): string
    {
        return '@src/Agent/Web/Layout/layout.php';
    }
}
