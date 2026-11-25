<?php

declare(strict_types=1);

namespace App\Order58\Web\RecentCalls;

use App\Integration\Order58Recording\FixtureAvailability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Order58 Recent Calls (GET /admin/order58/recent-calls).
 *
 * Pick one of the configured stores, press Load Calls, and its latest 25 calls are listed with each
 * call's three recordings — mixed, customer and agent — to play or download, and its receipt.
 *
 * **Opening the page contacts nobody.** It renders the dropdown and an empty table; the call list is
 * fetched by the Load Calls button from {@see ListAction}, and a recording only when its Play or Download
 * is pressed, from {@see AudioAction}. Nothing on this page writes to the database or keeps a file.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'stores' => RecentCallsSettings::options(),
                'selectedStore' => RecentCallsSettings::find($params['store'] ?? null) ?? RecentCallsSettings::DEFAULT_STORE,
                'limit' => RecentCallsSettings::LIMIT,
                'channelColumns' => RecentCallsSettings::channelColumns(),
                // Development and test only, and only when this request asked for it. Carried into the
                // list request, whose answers carry it into every Play and Download address.
                'useFixtures' => FixtureAvailability::isRequested($params[FixtureAvailability::QUERY_PARAMETER] ?? null),
            ]);
    }
}
