<?php

declare(strict_types=1);

namespace App\Rules\Web\Detail;

use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Rules\Contract\RuleReportReaderInterface;
use App\Shared\Domain\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function uasort;

/**
 * The rule review page (GET /admin/order58/rules/{ruleId}).
 *
 * Shows one canonical rule's full content, classification, matched/suggested store, source rows, searchable
 * document status and classification history, plus the review actions valid for its current state. A missing
 * rule raises a not-found domain exception → 404 via the middleware. Renders from local state — no API call.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private RuleReportReaderInterface $reader,
        private Order58StoreRepositoryInterface $stores,
    ) {}

    public function __invoke(#[RouteArgument] string $ruleId): ResponseInterface
    {
        $detail = $this->reader->findDetail((int) $ruleId);
        if ($detail === null) {
            throw new NotFoundException('rule_not_found', 'Rule not found.');
        }

        // The store picker: every mirrored store as {source_id => name}, sorted by name, so a match saves the
        // store's source_id (never only its name).
        $storeOptions = [];
        foreach ($this->stores->allMirrors() as $store) {
            $storeOptions[$store->sourceId] = $store->name;
        }
        uasort($storeOptions, static fn(string $a, string $b): int => $a <=> $b);

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'rule' => $detail,
                'storeOptions' => $storeOptions,
            ]);
    }
}
