<?php

declare(strict_types=1);

namespace App\Order58\Domain\CaseOrders;

use function in_array;
use function is_string;
use function preg_match;
use function trim;

/**
 * What the admin Case Orders listing was asked for.
 *
 * Built only from the query string, and every value is shape-checked here rather than at the query:
 * a listing reads whatever a URL carries, so the repository should never have to wonder whether a
 * sort key is one it knows.
 */
final readonly class CaseOrderListQuery
{
    public const SORTS = ['ordered', 'order', 'store', 'synced', 'items'];

    public const ITEM_STATUSES = ['PENDING', 'QUEUED', 'PROCESSING', 'SYNCED', 'EMPTY', 'FAILED'];

    private function __construct(
        public ?int $storeSourceId,
        public string $search,
        public string $orderedFrom,
        public string $orderedTo,
        public string $syncedFrom,
        public string $syncedTo,
        public string $itemsStatus,
        /** `yes`, `no` or empty for either. */
        public string $recording,
        public string $demo,
        public string $sort,
        public bool $descending,
        public int $page,
    ) {}

    /** @param array<array-key, mixed> $params */
    public static function fromParams(array $params): self
    {
        $sort = self::text($params['sort'] ?? null);
        $status = self::text($params['items'] ?? null);

        return new self(
            storeSourceId: self::digits($params['store'] ?? null),
            search: self::text($params['q'] ?? null),
            orderedFrom: self::date($params['from'] ?? null),
            orderedTo: self::date($params['to'] ?? null),
            syncedFrom: self::date($params['sfrom'] ?? null),
            syncedTo: self::date($params['sto'] ?? null),
            itemsStatus: in_array($status, self::ITEM_STATUSES, true) ? $status : '',
            recording: self::yesNo($params['rec'] ?? null),
            demo: self::yesNo($params['demo'] ?? null),
            sort: in_array($sort, self::SORTS, true) ? $sort : 'ordered',
            // Newest first by default, which is what somebody looking for a recent order wants.
            descending: ($params['dir'] ?? 'desc') !== 'asc',
            page: self::digits($params['page'] ?? null) ?? 1,
        );
    }

    /** Whether anything at all is narrowing the list — drives the Reset control. */
    public function isFiltered(): bool
    {
        return $this->storeSourceId !== null
            || $this->search !== ''
            || $this->orderedFrom !== ''
            || $this->orderedTo !== ''
            || $this->syncedFrom !== ''
            || $this->syncedTo !== ''
            || $this->itemsStatus !== ''
            || $this->recording !== ''
            || $this->demo !== '';
    }

    /**
     * The parameters that should ride along with a page link or a sort link.
     *
     * @return array<string, string>
     */
    public function queryParams(): array
    {
        $out = [];

        foreach ([
            'store' => $this->storeSourceId === null ? '' : (string) $this->storeSourceId,
            'q' => $this->search,
            'from' => $this->orderedFrom,
            'to' => $this->orderedTo,
            'sfrom' => $this->syncedFrom,
            'sto' => $this->syncedTo,
            'items' => $this->itemsStatus,
            'rec' => $this->recording,
            'demo' => $this->demo,
            'sort' => $this->sort === 'ordered' ? '' : $this->sort,
            'dir' => $this->descending ? '' : 'asc',
        ] as $key => $value) {
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function digits(mixed $raw): ?int
    {
        return is_string($raw) && preg_match('/^[1-9][0-9]{0,18}$/', $raw) === 1 ? (int) $raw : null;
    }

    private static function text(mixed $raw): string
    {
        return is_string($raw) ? trim($raw) : '';
    }

    private static function date(mixed $raw): string
    {
        return is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : '';
    }

    private static function yesNo(mixed $raw): string
    {
        return $raw === 'yes' || $raw === 'no' ? $raw : '';
    }
}
