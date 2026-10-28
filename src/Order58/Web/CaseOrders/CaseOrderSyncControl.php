<?php

declare(strict_types=1);

namespace App\Order58\Web\CaseOrders;

use App\Order58\Application\CaseOrders\CaseOrderSyncCoordinator;
use App\Order58\Client\CaseOrders\CaseOrderSettings;
use App\Order58\Domain\CaseOrders\CaseOrderSyncRun;
use Yiisoft\Router\UrlGeneratorInterface;

use function is_string;
use function preg_match;

/**
 * What a page needs to offer "Sync Order58 Case Orders": the one control, wherever it is rendered.
 *
 * ## Why this exists
 *
 * The button lives on two pages — Order58 Call Recordings and Manage Orders — and both must be the same
 * synchronisation: one endpoint ({@see SyncAction}), one coordinator deciding whether a press may start
 * a run, one dialog ({@see CaseOrderSyncViews}) and one script. This is the read half of that, moved out
 * of the Call Recordings action unchanged, so the second page asks the same questions in the same way
 * rather than holding a copy of them.
 *
 * Read-only, like every GET that renders it: nothing here may decide to synchronise.
 */
final readonly class CaseOrderSyncControl
{
    public function __construct(
        private CaseOrderSyncCoordinator $coordinator,
        /** Why the button would be unavailable, in words. Checked before anything is claimed. */
        private CaseOrderSettings $settings,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * The template variables the trigger and the dialog read, under the names both pages use.
     *
     * @param array<array-key, mixed> $params the page's own query string
     *
     * @return array{
     *     caseOrderSyncUrl: string,
     *     caseOrderStatusUrl: string,
     *     caseOrderRun: CaseOrderSyncRun|null,
     *     caseOrderBlockedReason: string|null,
     *     caseOrderProblems: list<string>,
     *     caseOrderConfirmOpen: bool,
     * }
     */
    public function viewParameters(array $params): array
    {
        return [
            'caseOrderSyncUrl' => $this->urlGenerator->generate('order58.case-orders.sync'),
            'caseOrderStatusUrl' => $this->urlGenerator->generate('order58.case-orders.status'),
            /*
             * The run the progress panel describes — **never simply the most recent one.**
             *
             * Rendering `latest()` here is what put a previous run's Completed badge, full bar
             * and its 200/723 counts inside the confirmation dialog before anything had been
             * confirmed. A finished run is history; it belongs to the Case Orders page's own
             * table, not to a panel whose whole job is to describe the operation in progress.
             *
             * So: the run actually running, or — after the synchronisation POST redirects back —
             * the one named by `sync-run`, which is that operator's own run and nobody else's.
             * With neither, the panel renders nothing and the dialog opens clean.
             */
            'caseOrderRun' => $this->coordinator->active() ?? $this->requestedRun($params),
            /*
             * Whether the button may be pressed, asked of the coordinator rather than inferred.
             *
             * The panel shows a run whether or not another may start, so the presence of a run says
             * nothing about availability — deciding it from `caseOrderRun` disabled the button
             * permanently from the first run onwards. The coordinator owns the rate and lease rules;
             * this is the same answer the next press would get.
             */
            'caseOrderBlockedReason' => $this->coordinator->manualBlockedReason(),
            'caseOrderProblems' => $this->settings->problems(),
            // `?confirm-case-orders=1` renders the dialog already open, which is what makes the
            // confirmation reachable with no script. The flag only ever opens a dialog, so an
            // unexpected value is simply not it rather than an error.
            'caseOrderConfirmOpen' => ($params['confirm-case-orders'] ?? null) === '1',
        ];
    }

    /**
     * The run named by `?sync-run=`, when it exists.
     *
     * Written by the synchronisation POST's redirect, so an operator comes back to their own result
     * rather than to whatever ran last. Any unreadable or unknown value resolves to null rather than
     * to a different run: this decides which run is described, so a guessed id must show nothing
     * instead of somebody else's counts.
     *
     * @param array<array-key, mixed> $params
     */
    private function requestedRun(array $params): ?CaseOrderSyncRun
    {
        $raw = $params['sync-run'] ?? null;

        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,18}$/', $raw) !== 1) {
            return null;
        }

        return $this->coordinator->find((int) $raw);
    }
}
