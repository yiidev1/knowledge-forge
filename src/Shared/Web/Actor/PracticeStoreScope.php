<?php

declare(strict_types=1);

namespace App\Shared\Web\Actor;

use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function preg_match;
use function sprintf;

/**
 * Which stores and recordings an Order58 agent may reach on the practice surface.
 *
 * ## The rule, stated once
 *
 * **A store is visible to an agent when it holds at least one uploaded recording that still has its
 * audio and names a source order that exists in that same store — or at least one Case Order call
 * recording link on one of its orders.**
 *
 * The second half arrived with the Case Order `calls` data. Those recordings are not uploaded: their
 * links are stored in `order58_call_sessions` / `order58_call_recordings` and the audio is streamed from
 * the provider when somebody presses Play. A link exists only on a stored order of the same store — the
 * foreign key guarantees it — so the "names a real order of this store" half holds by construction.
 *
 * That is the whole predicate. Deliberately *not* part of it: `knowledge_bases.agent_enabled`, chat
 * eligibility, vector-store readiness and `source_active`. Those govern Store Chat and answer a
 * different question — whether a store can be *talked to*. This surface asks whether there is anything
 * to practise on, and a store with recordings and mirrored orders has that whether or not its chat was
 * ever provisioned. Each of those three is asserted separately in the integration suite, so "we did not
 * use them" is a checked fact rather than a claim.
 *
 * ## Why `retained_audio_path IS NOT NULL` is in it
 *
 * A row in `audio_conversations` is not a recording. `audio_transcription_jobs.retained_audio_path` is
 * nullable and nothing requires a conversation to have a job at all, so a conversation can exist with
 * no audio behind it. The listing already distinguishes the two — it is the same expression the store
 * page's `has_original` uses — so this reuses that notion rather than inventing a second one. Without
 * it, an agent would be offered a store whose every player is empty.
 *
 * No transcription-status condition. A recording mid-transcription is present and playable, the admin
 * store page lists exactly those rows, and there is no evidence for hiding them.
 *
 * ## Why the store id is in the correlation
 *
 * `order58_orders.source_order_id` is unique only *within* an account — the migration that created it
 * says so outright. Correlating on the order id alone would let one merchant's order number make
 * another merchant's store visible to an agent who has no business with it.
 *
 * ## One predicate, every caller
 *
 * {@see storeSql()} is the SQL; {@see admitsStore()}, {@see admitsJob()} and
 * {@see admitsCaseRecording()} are the object-level answers. The directory listing builds its query
 * from `storeSql()` too, and the practice listing narrows its orders with {@see orderSql()}, the same
 * rule one level down — so a store that passes the list cannot fail the row check or the other way
 * round, the hole a second copy would open.
 *
 * ## Fails closed
 *
 * Any error — a dropped connection, a malformed id — returns false. A scope check that cannot prove
 * access must not grant it.
 *
 * Addressed by table name rather than through any module's repositories, because this file sits in the
 * shared namespace so the authorization middleware can use it without naming the audio module, which
 * `ModuleIsolationTest` forbids. The same idiom {@see OrderTestingJobScope} beside it already uses.
 */
final readonly class PracticeStoreScope
{
    private const JOBS = '{{%audio_transcription_jobs}}';
    private const CONVERSATIONS = '{{%audio_conversations}}';
    private const ORDERS = '{{%order58_orders}}';
    private const STORES = '{{%order58_stores}}';
    private const SESSIONS = '{{%order58_call_sessions}}';
    private const RECORDINGS = '{{%order58_call_recordings}}';

    /** The free-text guard every reader of `audio_conversations.order_id` applies before casting. */
    private const NUMERIC_ORDER = "REGEXP '^[1-9][0-9]{0,18}$'";

    public function __construct(private ConnectionInterface $connection) {}

    /**
     * The predicate as SQL, correlated on a store row the caller has already aliased.
     *
     * @param string $alias the alias of the `order58_stores` row to correlate against
     * @param string $column the column on that alias holding the store's source id
     */
    public static function storeSql(string $alias = 's', string $column = 'source_id'): string
    {
        return sprintf(
            '(EXISTS (SELECT 1 FROM `audio_conversations` `c`'
                . ' JOIN `audio_transcription_jobs` `j`'
                . ' ON `j`.`conversation_id` = `c`.`id` AND `j`.`retained_audio_path` IS NOT NULL'
                . ' JOIN `order58_orders` `o`'
                . ' ON `o`.`account_id` = `c`.`store_source_id`'
                . ' AND `o`.`source_order_id` = CAST(`c`.`order_id` AS UNSIGNED)'
                . ' WHERE `c`.`store_source_id` = `%1$s`.`%2$s`'
                . ' AND `c`.`order_id` %3$s)'
                . ' OR EXISTS (SELECT 1 FROM `order58_call_sessions` `cs`'
                . ' JOIN `order58_call_recordings` `cr`'
                . ' ON `cr`.`session_id` = `cs`.`id` AND `cr`.`delisted_at` IS NULL'
                . ' WHERE `cs`.`store_source_id` = `%1$s`.`%2$s`))',
            $alias,
            $column,
            self::NUMERIC_ORDER,
        );
    }

    /**
     * The same predicate one level down: does THIS order have something to practise on?
     *
     * Correlated on an `order58_orders` row the caller has aliased, so the practice listing can be
     * driven from the orders table and narrowed by this, rather than restating the rule. An uploaded
     * recording counts when its free-text order id is exactly this order's number (the digits form every
     * reader of that column accepts, so `CAST(... AS CHAR)` matches it and the index on
     * `(store_source_id, order_id)` serves the lookup); a Case Order link counts when it is current.
     *
     * @param string $alias the alias of the `order58_orders` row to correlate against
     */
    public static function orderSql(string $alias = 'o'): string
    {
        return sprintf(
            '(EXISTS (SELECT 1 FROM `audio_conversations` `pc`'
                . ' JOIN `audio_transcription_jobs` `pj`'
                . ' ON `pj`.`conversation_id` = `pc`.`id` AND `pj`.`retained_audio_path` IS NOT NULL'
                . ' WHERE `pc`.`store_source_id` = `%1$s`.`account_id`'
                . ' AND `pc`.`order_id` = CAST(`%1$s`.`source_order_id` AS CHAR))'
                . ' OR EXISTS (SELECT 1 FROM `order58_call_sessions` `ps`'
                . ' JOIN `order58_call_recordings` `pr`'
                . ' ON `pr`.`session_id` = `ps`.`id` AND `pr`.`delisted_at` IS NULL'
                . ' WHERE `ps`.`store_source_id` = `%1$s`.`account_id`'
                . ' AND `ps`.`source_order_id` = `%1$s`.`source_order_id`))',
            $alias,
        );
    }

    /** Does this store hold anything an agent may practise on? */
    public function admitsStore(int $storeSourceId): bool
    {
        if ($storeSourceId <= 0) {
            return false;
        }

        try {
            return (new Query($this->connection))
                ->from(['s' => self::STORES])
                ->where(['s.source_id' => $storeSourceId])
                ->andWhere(new Expression(self::storeSql()))
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * May an agent reach this recording?
     *
     * Two conditions, and both are needed. The job's own conversation must name an order that exists
     * in its store — otherwise the recording is not a practice order at all, whatever else the store
     * holds — and the store must itself qualify, which is what stops a single well-formed row in an
     * otherwise empty store opening the whole store's endpoints.
     *
     * Deliberately *not* conditioned on this job having retained audio: a transcript is readable for a
     * recording whose audio has aged out, and the file endpoints refuse on their own when there are no
     * bytes. Requiring it here would hide a readable transcript to prevent a download that already
     * cannot happen.
     *
     * @param string $jobPublicId the `{publicId}` every audio job route is keyed on
     */
    public function admitsJob(string $jobPublicId): bool
    {
        // Cheap shape check first: the route pattern already constrains this, but this class is
        // callable from anywhere and must not hand a stray value to the database.
        if (preg_match('/\A[0-9a-f]{32}\z/', $jobPublicId) !== 1) {
            return false;
        }

        try {
            $storeSourceId = (new Query($this->connection))
                ->select('c.store_source_id')
                ->from(['j' => self::JOBS])
                ->innerJoin(['c' => self::CONVERSATIONS], 'c.id = j.conversation_id')
                ->innerJoin(
                    ['o' => self::ORDERS],
                    'o.account_id = c.store_source_id'
                        . ' AND o.source_order_id = CAST(c.order_id AS UNSIGNED)',
                )
                ->where(['j.public_id' => $jobPublicId])
                ->andWhere(new Expression('c.order_id ' . self::NUMERIC_ORDER))
                ->scalar();
        } catch (Throwable) {
            return false;
        }

        if ($storeSourceId === false || $storeSourceId === null) {
            return false;
        }

        return $this->admitsStore((int) $storeSourceId);
    }

    /**
     * May an agent play this Case Order recording?
     *
     * The store, the order and the recording must all belong together, and the link must be current:
     * an id read off one order's row cannot be replayed against another order or another store. The
     * store's own admission is checked separately, by the store argument every such route carries.
     */
    public function admitsCaseRecording(int $storeSourceId, int $sourceOrderId, int $recordingId): bool
    {
        if ($storeSourceId <= 0 || $sourceOrderId <= 0 || $recordingId <= 0) {
            return false;
        }

        try {
            return (new Query($this->connection))
                ->from(['r' => self::RECORDINGS])
                ->innerJoin(['s' => self::SESSIONS], 's.id = r.session_id')
                ->where([
                    'r.id' => $recordingId,
                    's.store_source_id' => $storeSourceId,
                    's.source_order_id' => $sourceOrderId,
                    'r.delisted_at' => null,
                ])
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
