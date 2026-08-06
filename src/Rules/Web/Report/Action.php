<?php

declare(strict_types=1);

namespace App\Rules\Web\Report;

use App\Order58\Domain\SyncRunRepositoryInterface;
use App\Rules\Contract\RuleReportReaderInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * The read-only Order58 Rules report (GET /admin/order58/rules).
 *
 * Phase 1 shows the catalog summary: raw source totals, canonical unique rules, exact-duplicate source count,
 * possible-duplicate groups pending review, and the latest rules-sync counters. Classification, materialization
 * and the detailed per-rule table arrive in later phases. Renders entirely from local state — no API call.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private RuleReportReaderInterface $reader,
        private SyncRunRepositoryInterface $runs,
    ) {}

    public function __invoke(): ResponseInterface
    {
        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'summary' => $this->reader->summary(),
                'latest' => $this->runs->latestByType()['rules'] ?? null,
            ]);
    }
}
