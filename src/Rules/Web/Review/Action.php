<?php

declare(strict_types=1);

namespace App\Rules\Web\Review;

use App\Auth\Application\CurrentAdmin;
use App\Rules\Application\RuleReviewService;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin classification review (POST /admin/order58/rules/review).
 *
 * Applies one review decision to a canonical rule and returns via Post/Redirect/Get. The decision is persisted,
 * audited and its searchable projection is reconciled immediately by {@see RuleReviewService} — WITHOUT any
 * OpenAI call in the request (materialization only queues local document work for the worker). A missing rule
 * raises a not-found domain exception → 404 via the middleware.
 */
final readonly class Action
{
    public function __construct(
        private RuleReviewService $review,
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $form = FormData::fromRequest($request);
        $ruleId = (int) $form->string('rule_id');
        $storeId = (int) $form->string('store_id');
        $adminId = $this->currentAdmin->get()->id();

        $done = match ($form->string('action')) {
            'mark_common' => $this->run(fn() => $this->review->markCommon($ruleId, $adminId), 'Marked as a common rule.'),
            'mark_unresolved' => $this->run(fn() => $this->review->markUnresolved($ruleId, $adminId), 'Marked as unresolved.'),
            'ignore' => $this->run(fn() => $this->review->ignore($ruleId, $adminId), 'Rule ignored.'),
            'confirm_store' => $this->run(fn() => $this->review->confirmStore($ruleId, $storeId, $adminId), 'Store match confirmed.'),
            'reject_store' => $this->run(fn() => $this->review->rejectStore($ruleId, $storeId, $adminId), 'Store match rejected.'),
            'enable_global' => $this->run(fn() => $this->review->setGloballyAvailable($ruleId, true, $adminId), 'Rule enabled for global search.'),
            'disable_global' => $this->run(fn() => $this->review->setGloballyAvailable($ruleId, false, $adminId), 'Rule disabled from global search.'),
            'reprocess' => $this->run(fn() => $this->review->reprocess($ruleId, $adminId), 'Rule reprocessed.'),
            default => false,
        };

        if (!$done) {
            $this->flash->error('Unknown review action.');
        }

        // Return to the rule's review page (so the admin sees the updated state), falling back to the summary.
        return $ruleId > 0
            ? $this->redirect->afterPost('order58.rules.detail', ['ruleId' => $ruleId])
            : $this->redirect->afterPost('order58.rules');
    }

    private function run(callable $work, string $success): bool
    {
        $work();
        $this->flash->success($success);

        return true;
    }
}
