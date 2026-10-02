<?php

declare(strict_types=1);

namespace App\Order58\Web\Orders;

use App\Order58\Application\Orders\OrderDateRange;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use App\Order58\Domain\Orders\OrderListQuery;
use App\Order58\Domain\StoreDirectoryQuery;
use App\Order58\Domain\StoreDirectoryReaderInterface;
use App\Order58\Domain\StoreSourceStatusFilter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_string;
use function preg_match;

/**
 * The Order58 Orders page (GET /admin/order58/orders): the sync form, the last result, and the list.
 *
 * Opening it contacts no provider. The form is rendered and the local table is read — nothing else — so
 * an idle tab on this page costs an API nothing, the same rule the Calls page follows.
 */
final readonly class Action
{
    public function __construct(
        private WebViewRenderer $viewRenderer,
        private StoreDirectoryReaderInterface $stores,
        private Order58OrderRepositoryInterface $orders,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        $accountId = $this->positiveInt($query['account_id'] ?? null);
        $storeOptions = $this->storeOptions();

        // A store id in the URL that is not one of ours is dropped rather than queried: the filter is an
        // operator's convenience, not an authorization boundary, and silently honouring an arbitrary
        // number would let the list speak about an account this page never offered.
        if ($accountId !== null && !isset($storeOptions[$accountId])) {
            $accountId = null;
        }

        $listQuery = new OrderListQuery(
            accountId: $accountId,
            sourceOrderId: $this->positiveInt($query['order_id'] ?? null),
            status: $this->shortText($query['status'] ?? null),
            dateFrom: $this->date($query['list_from'] ?? null),
            dateTo: $this->date($query['list_to'] ?? null),
            page: $this->positiveInt($query['page'] ?? null) ?? 1,
        );

        $page = $this->orders->page($listQuery);

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'storeOptions' => $storeOptions,
                'rows' => $page['rows'],
                'total' => $page['total'],
                'listQuery' => $listQuery,
                'perPage' => OrderListQuery::PER_PAGE,
                'maxDates' => OrderDateRange::MAX_DATES,
                // The form's own selections, carried back so a sync does not reset the operator's choices.
                'formAccountId' => $this->positiveInt($query['form_account_id'] ?? null),
                'formFrom' => $this->date($query['form_from'] ?? null),
                'formTo' => $this->date($query['form_to'] ?? null),
            ])
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * Every store this application can sync orders for.
     *
     * Read through the directory rather than the mirror: the authoritative active flag is
     * `knowledge_bases.source_active`, because `order58_stores.active` is 0 for every row in this
     * database and a dropdown built on it would be empty.
     *
     * @return array<int, string> source id => name
     */
    private function storeOptions(): array
    {
        $result = $this->stores->search(new StoreDirectoryQuery(
            perPage: 1000,
            sourceStatus: StoreSourceStatusFilter::Active,
        ));

        $options = [];
        foreach ($result->items as $store) {
            // The source id rides along in the label because store names repeat — two "Magic Wok" rows
            // are indistinguishable otherwise, and picking the wrong one syncs the wrong account.
            $options[$store->sourceId] = $store->name;
        }

        return $options;
    }

    private function positiveInt(mixed $raw): ?int
    {
        return is_string($raw) && preg_match('/\A\d{1,12}\z/', $raw) === 1 && (int) $raw > 0
            ? (int) $raw
            : null;
    }

    private function date(mixed $raw): ?string
    {
        return is_string($raw) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $raw) === 1 ? $raw : null;
    }

    private function shortText(mixed $raw): ?string
    {
        return is_string($raw) && preg_match('/\A[a-z_]{1,32}\z/', $raw) === 1 ? $raw : null;
    }
}
