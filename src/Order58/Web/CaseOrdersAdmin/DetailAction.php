<?php

declare(strict_types=1);

namespace App\Order58\Web\CaseOrdersAdmin;

use App\Order58\Domain\Items\OrderItemRepositoryInterface;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Domain\Exception\NotFoundException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_string;
use function json_decode;

/**
 * One case order, read-only (GET …/case-orders/{sourceId}/{sourceOrderId}).
 *
 * ## The identity is the pair, always
 *
 * `source_order_id` is unique only within a store, so both halves are route arguments and both reach
 * the query. A detail page keyed on the order number alone would show one merchant's order under
 * another's URL.
 *
 * ## What is shown, and what is not
 *
 * The stored line rows, and the canonical shape the comparison is scored against — products,
 * modifiers, additionals and combinations, which is what somebody checking a trainee's work needs.
 * The raw provider payload is NOT rendered: it is redacted on the way in, but it is also a customer's
 * whole record, and this page has no reason to reprint it.
 *
 * ## `?fragment=1`
 *
 * The same order, as the bare body with no layout, for the View dialog on the list page. It is a
 * parameter on this route rather than a route of its own so that the fragment cannot be reached by
 * anyone who could not already open the page: one action, one admin group, one lookup, and no second
 * place for the authorization to be forgotten. The page remains the no-script fallback.
 */
final readonly class DetailAction
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private OrderItemRepositoryInterface $items,
        private ConnectionInterface $connection,
        private AppTimeZone $appTimeZone,
        private UrlGeneratorInterface $urlGenerator,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        #[RouteArgument]
        int $sourceId,
        #[RouteArgument]
        int $sourceOrderId,
    ): ResponseInterface {
        /** @var array<string, mixed>|null $order */
        $order = (new Query($this->connection))
            ->select([
                'account_id', 'source_order_id', 'seq', 'uid', 'status', 'type', 'payment_method',
                'total_amount', 'total_cost', 'discount', 'tax', 'tip', 'shipping_fee',
                'reservation_phone', 'source_created_at', 'items_status', 'items_count',
                'items_synced_at', 'synced_at', 'created_at', 'updated_at', 'content_hash',
            ])
            ->from('{{%order58_orders}}')
            // Both halves. See the class docblock.
            ->where(['account_id' => $sourceId, 'source_order_id' => $sourceOrderId])
            ->one();

        if ($order === null) {
            throw new NotFoundException('case_order_not_found', 'No such case order is stored.');
        }

        $storeName = (new Query($this->connection))
            ->select('name')
            ->from('{{%order58_stores}}')
            ->where(['source_id' => $sourceId])
            ->scalar();

        $decode = static fn(?string $json): array => $json === null
            ? []
            : (array) (json_decode($json, true) ?? []);

        $data = [
            'order' => $order,
            'items' => $this->items->forOrder($sourceId, $sourceOrderId),
            // Whether a recording and a demo exist, as two bounded reads rather than per-row ones.
            'recordings' => $this->recordings($sourceId, $sourceOrderId),
            'demoCount' => $this->demoCount($sourceId, $sourceOrderId),
            'appTimeZone' => $this->appTimeZone,
            'decode' => $decode,
        ];

        if (($request->getQueryParams()['fragment'] ?? null) === '1') {
            return $this->fragment($this->viewRenderer->renderPartialAsString(
                __DIR__ . '/_detail-body',
                $data,
            ));
        }

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/detail', $data + [
                'storeName' => is_string($storeName) ? $storeName : null,
                'listUrl' => $this->urlGenerator->generate('order58.case-orders'),
            ]);
    }

    /** A customer's order: never cached, never sniffed, and no layout around it. */
    private function fragment(string $html): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $response->getBody()->write($html);

        return $response;
    }

    /** @return list<array<string, mixed>> */
    private function recordings(int $storeSourceId, int $sourceOrderId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->select([
                'public_id' => 'c.public_id',
                'recording_type' => 'c.recording_type',
                'created_at' => 'c.created_at',
            ])
            ->from(['c' => '{{%audio_conversations}}'])
            ->where(['c.store_source_id' => $storeSourceId])
            // The digits guard every reader of this free-text column uses before casting.
            ->andWhere(new \Yiisoft\Db\Expression\Expression("c.order_id REGEXP '^[1-9][0-9]{0,18}$'"))
            ->andWhere(['=', new \Yiisoft\Db\Expression\Expression('CAST(c.order_id AS UNSIGNED)'), $sourceOrderId])
            ->all();

        return $rows;
    }

    private function demoCount(int $storeSourceId, int $sourceOrderId): int
    {
        return (int) (new Query($this->connection))
            ->from('{{%order_testing_demo_orders}}')
            ->where(['store_source_id' => $storeSourceId, 'source_order_id' => $sourceOrderId])
            ->count();
    }
}
