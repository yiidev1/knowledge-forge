<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure;

use App\Agent\Domain\PracticeStore;
use App\Agent\Domain\PracticeStoreDirectoryInterface;
use App\Shared\Web\Actor\PracticeStoreScope;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function is_array;

use const SORT_ASC;

/**
 * The agent practice picker's list, read from the mirrored store table.
 *
 * ## The predicate is not written here
 *
 * It comes from {@see PracticeStoreScope::storeSql()}, the same string the middleware's object-level
 * check is built from. That is deliberate: a listing and a gate that each carried their own copy would
 * eventually disagree, and the disagreement is a hole — a store an agent can open but cannot see, or
 * worse, one they can see and the gate then refuses halfway through.
 *
 * Explicitly **not** consulted: `knowledge_bases.agent_enabled`, chat eligibility, vector-store
 * readiness and `order58_stores.active`. Those govern Store Chat. A store that was deactivated in
 * Order58 last week still has last month's recordings and the orders behind them, and an agent
 * practising on them is the point of this surface.
 *
 * ## The count is the same join, aggregated
 *
 * It is the number of recordings an agent may actually practise on — the rows the predicate admits, not
 * every conversation the store has. A card promising forty and opening onto four would be worse than no
 * number at all.
 */
final readonly class DbPracticeStoreDirectory implements PracticeStoreDirectoryInterface
{
    private const STORES = '{{%order58_stores}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function findAvailable(): array
    {
        $rows = (new Query($this->connection))
            ->select([
                's.source_id',
                's.name',
                's.company',
                'recordings' => new Expression(
                    '(SELECT COUNT(*) FROM `audio_conversations` `rc`'
                    . ' JOIN `audio_transcription_jobs` `rj`'
                    . ' ON `rj`.`conversation_id` = `rc`.`id`'
                    . ' AND `rj`.`retained_audio_path` IS NOT NULL'
                    . ' JOIN `order58_orders` `ro`'
                    . ' ON `ro`.`account_id` = `rc`.`store_source_id`'
                    . ' AND `ro`.`source_order_id` = CAST(`rc`.`order_id` AS UNSIGNED)'
                    . ' WHERE `rc`.`store_source_id` = `s`.`source_id`'
                    . " AND `rc`.`order_id` REGEXP '^[1-9][0-9]{0,18}$')"
                    /*
                     * Plus the Case Order call recordings, counted only for orders with no uploaded
                     * recording — the page plays the uploaded one for those, so counting both would
                     * offer the agent the same call twice.
                     */
                    . ' + (SELECT COUNT(*) FROM `order58_call_sessions` `cs`'
                    . ' JOIN `order58_call_recordings` `cr`'
                    . ' ON `cr`.`session_id` = `cs`.`id` AND `cr`.`delisted_at` IS NULL'
                    . ' WHERE `cs`.`store_source_id` = `s`.`source_id`'
                    . ' AND NOT EXISTS (SELECT 1 FROM `audio_conversations` `nc`'
                    . ' JOIN `audio_transcription_jobs` `nj`'
                    . ' ON `nj`.`conversation_id` = `nc`.`id` AND `nj`.`retained_audio_path` IS NOT NULL'
                    . ' WHERE `nc`.`store_source_id` = `cs`.`store_source_id`'
                    . ' AND `nc`.`order_id` = CAST(`cs`.`source_order_id` AS CHAR)))',
                ),
            ])
            ->from(['s' => self::STORES])
            ->where(new Expression(PracticeStoreScope::storeSql()))
            ->orderBy(['s.name' => SORT_ASC, 's.source_id' => SORT_ASC])
            ->all();

        $stores = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $stores[] = new PracticeStore(
                sourceId: (int) $row['source_id'],
                name: (string) $row['name'],
                company: $row['company'] === null ? null : (string) $row['company'],
                recordingCount: (int) $row['recordings'],
            );
        }

        return $stores;
    }
}
