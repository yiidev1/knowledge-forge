<?php

declare(strict_types=1);

namespace App\AudioToText\Web\PracticeOrders;

use App\AudioToText\Domain\ConversationCapabilities;
use App\AudioToText\Domain\OrderGroupFilter;
use App\AudioToText\Web\OrderTesting\StoreAction as OrderTestingStoreAction;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One store's practice orders: where an administrator or operation user **uses** training content.
 *
 * ## Managing and using are two pages now
 *
 * Topics are assigned on Order Testing, where the recordings are managed. This page is the other half:
 * listen to a real call, see which topic it is practised under, recreate the order, compare the result.
 * It is the same page an agent sees, and that is the point — the three realms practise identically, so
 * there is one listing to keep correct instead of two that drift.
 *
 * Everything that changes something is therefore off: no upload, no replacement, no transcription, no
 * AI audio, no full editor, no topic assignment and no demo-order import. Not hidden — **not emitted**,
 * because {@see capabilities()} answers no and the template asks before it renders. The one write this
 * surface keeps is Demo URL, which is the exercise itself.
 *
 * The administrator has lost nothing: every one of those controls is on Order Testing, where they
 * already were, behind the gate they already passed.
 *
 * ## Only what can be practised is listed
 *
 * {@see groupFilter()} narrows with the predicate that decides whether a store is visible on the agent
 * surface at all, applied one row further down. A recording whose order id names nothing is on Order
 * Testing, where it can be seen and fixed; it is not here, where it could be neither practised nor
 * demo-tested.
 */
readonly class StoreAction extends OrderTestingStoreAction
{
    /**
     * Look, listen, and press Demo URL.
     *
     * The same profile the agent surface uses. An administrator reading this page is doing the same
     * thing an agent is, and a control that changes a recording belongs on the page where recordings
     * are managed — not on two pages, one of which is meant for practising.
     */
    protected function capabilities(): ConversationCapabilities
    {
        return ConversationCapabilities::readOnly();
    }

    /**
     * Whatever topics the reader chose, narrowed to orders that can actually be practised.
     *
     * It travels inside the filter, which is what carries it into the count as well as the page. A
     * listing narrowed without its pager is the bug the filter contract exists to prevent: fifteen
     * promised, four shown.
     */
    protected function groupFilter(ServerRequestInterface $request): OrderGroupFilter
    {
        return parent::groupFilter($request)?->withLinkedOrdersOnly()
            ?? OrderGroupFilter::linkedOrdersOnly();
    }

    protected function surfaceRoutes(): array
    {
        // Literals, like every cross-surface link in this file's neighbours: a route name is not a
        // namespace. Only the three addresses that belong to this surface are replaced; the group and
        // job endpoints stay on the Order Testing ones, which both realms that reach this page already
        // have. The topic-assignment names are inherited and never rendered — see capabilities().
        return array_merge(parent::surfaceRoutes(), [
            'page' => 'practice-orders',
            'pageLabel' => 'Practice Orders',
            'store' => 'practice-orders.store',

            // Read-only, so the conversation dialog builds no edit form. The job-scoped *reads* stay
            // on the audio module's own addresses, which both realms that reach this page already
            // have; only the one that decides what the dialog may offer is this surface's own.
            'jobFragment' => 'practice-orders.job.review.fragment',
        ]);
    }

    protected function storeMissing(): ResponseInterface
    {
        return $this->redirect->toRoute('practice-orders');
    }

    /**
     * Unreachable: no upload route is mounted for this surface and the form that would post to one is
     * not rendered. Answered anyway, so a route wired by mistake lands the reader back here.
     */
    protected function afterUpload(string $conversationId, int $sourceId): ResponseInterface
    {
        return $this->redirect->afterPost('practice-orders.store', ['sourceId' => $sourceId]);
    }
}
