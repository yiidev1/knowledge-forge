<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\CaseRecording;
use App\AudioToText\Domain\CaseRecordingReaderInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_keys;
use function count;
use function preg_match;

use const SORT_ASC;

/**
 * The current Case Order call recordings of one page of orders, in one query.
 *
 * The tables belong to the module that synchronises Case Orders; this module may not name it, so they
 * are read by name — the same arrangement {@see DbDemoOrderCountReader} and the topic reader live with.
 *
 * The query is bounded by the store and the page's order ids, so it walks the unique key on
 * `(store_source_id, source_order_id, call_session_id)` for fifteen orders and the recordings' unique
 * key for their sessions. The URL column is never selected: the page does not need it and must not have
 * it.
 */
final readonly class DbCaseRecordingReader implements CaseRecordingReaderInterface
{
    private const SESSIONS = '{{%order58_call_sessions}}';
    private const RECORDINGS = '{{%order58_call_recordings}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function forOrders(int $storeSourceId, array $orderIds): array
    {
        $wanted = [];

        foreach ($orderIds as $orderId) {
            // The rows' order ids come from free text as well as from the orders table; only an order
            // number is worth asking about, in the digits form the orders table itself uses.
            if (preg_match('/\A[1-9]\d{0,18}\z/', $orderId) === 1) {
                $wanted[$orderId] = true;
            }
        }

        if ($wanted === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->select([
                'source_order_id' => 's.source_order_id',
                'call_session_id' => 's.call_session_id',
                'recording_id' => 'r.id',
            ])
            ->from(['s' => self::SESSIONS])
            ->innerJoin(['r' => self::RECORDINGS], 'r.session_id = s.id AND r.delisted_at IS NULL')
            ->where(['s.store_source_id' => $storeSourceId, 's.source_order_id' => array_keys($wanted)])
            ->orderBy(['s.source_order_id' => SORT_ASC, 's.id' => SORT_ASC, 'r.position' => SORT_ASC])
            ->all();

        $recordings = [];

        foreach ($rows as $row) {
            $orderId = (string) $row['source_order_id'];
            $number = isset($recordings[$orderId]) ? count($recordings[$orderId]) + 1 : 1;

            $recordings[$orderId][] = new CaseRecording(
                (int) $row['recording_id'],
                (string) $row['call_session_id'],
                $number,
            );
        }

        return $recordings;
    }
}
