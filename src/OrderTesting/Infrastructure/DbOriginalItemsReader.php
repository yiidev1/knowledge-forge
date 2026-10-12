<?php

declare(strict_types=1);

namespace App\OrderTesting\Infrastructure;

use App\OrderTesting\Domain\Comparison\ItemAvailability;
use App\OrderTesting\Domain\Comparison\NormalizedItem;
use App\OrderTesting\Domain\Comparison\OriginalItems;
use App\OrderTesting\Domain\Comparison\OriginalItemsReaderInterface;
use App\Shared\Order58\Items\CanonicalItem;
use JsonException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;
use const SORT_ASC;

/**
 * Reads `order58_orders` item metadata and `order58_order_items` by table name.
 *
 * The tables belong to the Order58 module and this one does not import its classes — the same
 * arrangement {@see DbSourceOrderReader} already uses, for the same reason: a table is a shared
 * contract, a namespace is a dependency.
 *
 * ## Two queries, whatever the order contains
 *
 * One row for the synchronisation state, one bounded read for the lines. The item query is skipped
 * entirely when the state says the lines may not be believed, so the "never synchronised" case — the
 * common one until somebody enables the feature — costs a single query. Nothing here runs per item, so
 * an order with forty lines costs exactly what an order with one costs.
 *
 * ## `comparison_json`, not the raw columns
 *
 * The canonical form was computed once, at sync time, by the shared canonicaliser. Reading it back
 * means decoding one small object per line instead of re-decoding the provider's `data` blob and
 * re-deriving names and modifiers on every page view. The verbatim columns stay beside it for anybody
 * who needs what the provider literally said.
 *
 * A line whose `comparison_json` will not decode is skipped rather than failing the page, and the
 * verbatim row is still in the table to re-derive from.
 */
final readonly class DbOriginalItemsReader implements OriginalItemsReaderInterface
{
    private const ORDERS = '{{%order58_orders}}';
    private const ITEMS = '{{%order58_order_items}}';

    /**
     * Above this, the page would be unreadable anyway and the provider has sent something unexpected.
     *
     * A ceiling rather than pagination: the comparison is a side-by-side table a person reads, and an
     * order with more lines than this is a data problem to look at, not a page to scroll.
     */
    private const MAX_ITEMS = 200;

    public function __construct(private ConnectionInterface $connection) {}

    public function read(int $storeSourceId, int $sourceOrderId): OriginalItems
    {
        $row = (new Query($this->connection))
            ->select(['items_status', 'items_synced_at'])
            ->from(self::ORDERS)
            // Both, always. See OriginalItemsReaderInterface.
            ->where(['account_id' => $storeSourceId, 'source_order_id' => $sourceOrderId])
            ->one();

        if (!is_array($row)) {
            // The order is not mirrored at all. The page already says so for the order itself.
            return OriginalItems::neverRequested();
        }

        $syncedAt = $row['items_synced_at'] === null ? null : (string) $row['items_synced_at'];

        $availability = ItemAvailability::of(
            $row['items_status'] === null ? null : (string) $row['items_status'],
            // The one thing that separates "never successfully synced" from "a snapshot is still there".
            $syncedAt !== null,
        );

        if (!$availability->isComparable()) {
            // No second query: there is nothing the page may compare against.
            return OriginalItems::of($availability, [], $syncedAt);
        }

        return OriginalItems::of($availability, $this->items($storeSourceId, $sourceOrderId), $syncedAt);
    }

    /** @return list<NormalizedItem> */
    private function items(int $storeSourceId, int $sourceOrderId): array
    {
        $rows = (new Query($this->connection))
            ->select(['comparison_json'])
            ->from(self::ITEMS)
            ->where(['store_account_id' => $storeSourceId, 'source_order_id' => $sourceOrderId])
            // The provider's own order, which is what the page shows.
            ->orderBy(['position' => SORT_ASC, 'id' => SORT_ASC])
            ->limit(self::MAX_ITEMS)
            ->all();

        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row) || $row['comparison_json'] === null) {
                continue;
            }

            try {
                /** @var mixed $decoded */
                $decoded = json_decode((string) $row['comparison_json'], true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (!is_array($decoded)) {
                continue;
            }

            $items[] = NormalizedItem::fromCanonical(CanonicalItem::fromArray($decoded));
        }

        return $items;
    }
}
