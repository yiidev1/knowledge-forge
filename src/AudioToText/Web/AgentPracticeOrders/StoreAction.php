<?php

declare(strict_types=1);

namespace App\AudioToText\Web\AgentPracticeOrders;

use App\AudioToText\Domain\ConversationCapabilities;
use App\AudioToText\Web\PracticeOrders\StoreAction as PracticeStoreAction;
use Psr\Http\Message\ResponseInterface;

/**
 * One store's practice orders, as an agent sees them.
 *
 * ## The same page, with everything that writes taken out
 *
 * This renders the **same template** the other two practice surfaces render. Not a copy: what an order
 * row is, how a recording plays, how a conversation opens and what every dialog says are identical for
 * an agent, and a second copy would be a second place for all of it to drift. What differs is three
 * things, and each is a seam the page already had:
 *
 * - {@see capabilities()} — the agent may change nothing, so every control that would change something
 *   is **not emitted**. Not hidden: absent, with no element and no `data-` hook for the script to find.
 * - {@see surfaceRoutes()} — the agent's own addresses, so no link leads to an administrator page.
 * - {@see layoutPath()} — the agent shell, because the administrator sidebar lists places an agent
 *   cannot go.
 *
 * ## Why it lives here and not in `src/Agent/`
 *
 * `ModuleIsolationTest` forbids anything outside this module from naming `App\AudioToText`, and this
 * class must name its parent. So the agent-facing *page* lives in the audio module — exactly as the
 * administrator Practice Orders page does — while the agent module owns the gate, the picker and the
 * store directory. The dependency runs one way: this class names no agent code at all, only route
 * names, which are strings.
 *
 * ## Only what can be practised is listed
 *
 * {@see groupFilter()} narrows the listing with the same predicate that decides whether the store is
 * visible at all. A recording whose order id names nothing is still on the administrator's page, where
 * it can be seen and fixed; it is not on this one, where it could be neither practised nor demo-tested.
 *
 * ## Topics are shown, never set
 *
 * An agent sees which topic an order is practised under and can filter by it, because that is the
 * point of the exercise. Assigning one is a curation decision and is not theirs: the capability is off,
 * the picker and the bulk toolbar are not rendered, and the endpoints are not mounted for them.
 */
final readonly class StoreAction extends PracticeStoreAction
{
    /**
     * This agent's own demo orders, and nobody else's.
     *
     * The count behind "View (N)" used to be every demo order of the source order, so an agent saw
     * a link to results they had not produced — including one attributed to nobody at all. It is
     * narrowed in the query, not in the template: rows that are not this agent's never leave the
     * database, so a page that rendered the cell differently could not leak them.
     *
     * `CurrentActor` is Shared, not another business module, so reading it here breaks no isolation
     * rule. Its realm is lower case and the attempts table stores upper, which is the one
     * translation this method does.
     *
     * @return array{0: ?string, 1: ?int}
     */
    protected function demoCountActor(): array
    {
        // Only an agent narrows. Any other realm reaching this surface is a misconfiguration, and
        // showing it nothing is the safe answer to that.
        if (!$this->currentActor->isAgent()) {
            return ['AGENT', 0];
        }

        return ['AGENT', $this->currentActor->agentId()];
    }

    protected function capabilities(): ConversationCapabilities
    {
        return ConversationCapabilities::agentPractice();
    }

    protected function layoutPath(): string
    {
        return '@src/Agent/Web/Layout/layout.php';
    }

    protected function surfaceRoutes(): array
    {
        // Literals, as everywhere else in this file's neighbours: a route name is not a namespace, so
        // naming the agent pages here costs this module no dependency.
        //
        // Every one of these is a GET that reads. The write this surface allows — Demo URL — is reached
        // from the row itself through `demoUrl`, and it is the only one.
        return array_merge(parent::surfaceRoutes(), [
            'page' => 'agent.practice-orders',
            'pageLabel' => 'Practice Orders',
            'store' => 'agent.practice-orders.store',
            'arriving' => 'agent.practice-orders.store.arriving',
            'demoUrl' => 'agent.practice-orders.demo-url',
            'recordings' => 'agent.practice-orders.store.group.recordings',
            'transcripts' => 'agent.practice-orders.store.group.transcripts',
            'demoOrders' => 'agent.practice-orders.demo-orders',

            // The job-scoped reads, at this surface's own addresses. Without these the conversation
            // dialog would fetch the administrator URL, be refused, and simply never fill — a failure
            // no markup test and no unauthenticated test can see.
            'jobOriginalFile' => 'agent.practice-orders.job.original.file',
            'jobFragment' => 'agent.practice-orders.job.review.fragment',
            'jobStatus' => 'agent.practice-orders.job.status',

            // Not mounted for an agent, and never rendered for one either: the capabilities answer no
            // to transcribing and to the full editor, so no control carries these. They are named so
            // the map is complete rather than half-inherited.
            'jobTranscribe' => 'agent.practice-orders.job.status',
            'jobReview' => 'agent.practice-orders.store',
        ]);
    }

    protected function storeMissing(): ResponseInterface
    {
        return $this->redirect->toRoute('agent.practice-orders');
    }

    /**
     * Unreachable: no upload route is mounted for an agent, and the form that would post to one is not
     * rendered. Answered anyway rather than left to the parent's administrator address, so that a route
     * wired by mistake lands an agent back on their own page instead of somewhere they cannot see.
     */
    protected function afterUpload(string $conversationId, int $sourceId): ResponseInterface
    {
        return $this->redirect->afterPost('agent.practice-orders.store', ['sourceId' => $sourceId]);
    }
}
