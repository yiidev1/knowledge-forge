<?php

declare(strict_types=1);

namespace App\Order58\Infrastructure;

use App\Order58\Domain\Order58ImportStatus;
use App\Order58\Domain\RecordingAcquisitionReader;
use App\Shared\Audio\PendingRecordingPortInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_string;

use const SORT_DESC;

/**
 * What this module can tell the audio module about recordings still on their way in.
 *
 * ## Two statements, and neither of them is per row
 *
 * The first finds the calls that have an outstanding channel; the second reads those calls' channels in
 * full. It is deliberately not one query with a window function, and deliberately not a query per call:
 * the store page renders this on every load and polls it afterwards, so the cost has to stay flat as a
 * store's history grows. Both statements are bounded by {@see MAX_CALLS}, because a page can only draw
 * so many rows and an operator who has asked for two hundred calls at once needs the newest ones, not
 * all of them.
 *
 * ## It reports every mode, not only download-only
 *
 * A recording being fetched is being fetched whoever asked for it, and a reader looking at the store
 * page wants to know that either way. Scoping this to the download-only flow would have left the older
 * import path invisible for exactly the same minute, which was the original complaint.
 */
final readonly class Order58PendingRecordingProvider implements PendingRecordingPortInterface
{
    private const IMPORTS = '{{%order58_call_imports}}';

    /** Enough to cover a page of a store's calls several times over. */
    private const MAX_CALLS = 60;

    public function __construct(private ConnectionInterface $connection) {}

    public function activeForStore(int $storeSourceId): array
    {
        // Narrowed on the leading column of `ux_order58_call_imports_identity` and filtered on the two
        // non-terminal statuses, so the common answer — nothing outstanding — costs one index lookup.
        //
        // Strings, not ints: the driver hands back column values as strings, and a call session id is
        // an identifier the provider chose rather than a number.
        /** @var list<string> $active */
        $active = (new Query($this->connection))
            ->select(['call_session_id'])
            ->distinct()
            ->from(self::IMPORTS)
            ->where([
                'store_source_id' => $storeSourceId,
                'status' => [Order58ImportStatus::Pending->value, Order58ImportStatus::Fetching->value],
            ])
            ->orderBy(['call_session_id' => SORT_DESC])
            ->limit(self::MAX_CALLS)
            ->column();

        if ($active === []) {
            return [];
        }


        // Now every channel of those calls, including the ones already here: the reader needs to see
        // "mixed arrived, the caller side is still coming" as one row rather than as a row and a half.
        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->select(['call_session_id', 'channel', 'status', 'order_id', 'call_time_raw', 'id'])
            ->from(self::IMPORTS)
            ->where(['store_source_id' => $storeSourceId, 'call_session_id' => $active])
            ->orderBy(['id' => SORT_DESC])
            ->all();

        /** @var array<string, array<string, Order58ImportStatus>> $byCall */
        $byCall = [];
        /** @var array<string, array{order: ?string, time: ?string}> $facts */
        $facts = [];
        /**
         * The session ids in the order first seen — newest call first, because the rows are read that
         * way. Carried as strings rather than read back from the map's keys, which PHP would hand back
         * as integers.
         *
         * @var list<string> $ordered
         */
        $ordered = [];

        foreach ($rows as $row) {
            $status = Order58ImportStatus::fromStorage((string) $row['status']);

            if ($status === null) {
                continue;
            }

            $session = (string) $row['call_session_id'];
            $byCall[$session][(string) $row['channel']] = $status;

            if (!isset($facts[$session])) {
                $ordered[] = $session;

                // Identical across a call's rows — they are written in one statement — so the first
                // seen is the answer.
                $facts[$session] = [
                    'order' => $this->nullableString($row['order_id'] ?? null),
                    'time' => $this->nullableString($row['call_time_raw'] ?? null),
                ];
            }
        }

        $acquisitions = [];

        foreach ($ordered as $session) {
            $acquisition = RecordingAcquisitionReader::forCall(
                $session,
                $facts[$session]['order'],
                $facts[$session]['time'],
                $byCall[$session],
            );

            // The contract is outstanding work only. A call whose last channel settled between the two
            // statements above is dropped here rather than reported as arriving — the store page will
            // already be drawing it from its conversations.
            if ($acquisition !== null && $acquisition->isActive()) {
                $acquisitions[] = $acquisition;
            }
        }

        return $acquisitions;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
