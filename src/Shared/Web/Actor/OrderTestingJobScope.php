<?php

declare(strict_types=1);

namespace App\Shared\Web\Actor;

use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;
use function preg_match;

/**
 * Whether one audio job is reachable through the Order Testing workflow.
 *
 * ## Why knowing a job id is not permission
 *
 * Operation users may use the audio endpoints that the Order Testing store page calls — play a
 * recording, read a transcript, correct one, replace audio, generate TTS. Those endpoints are shared
 * with the Audio-to-Text module and are addressed only by `{publicId}`, so without a scope check an
 * operation user who learned any job's id could drive it, including jobs no Order Testing page lists.
 *
 * That is not hypothetical: `audio_conversations.store_source_id` and `.order_id` are **both nullable**,
 * so a conversation can exist that belongs to no store and no order. Hiding navigation would not stop a
 * direct request, so the rule is enforced here, server side, on every such request.
 *
 * ## The rule is a relationship, not a shape
 *
 * It is not enough that the conversation *has* a store id and a numeric order id. Those are the values a
 * row happens to carry; eligibility is whether they name something real that Order Testing actually
 * lists. So all four must hold:
 *
 *   1. the job exists and resolves to a conversation;
 *   2. that conversation carries a `store_source_id` **that exists in `order58_stores`**;
 *   3. it carries an `order_id` that is a plain positive integer (the same guard
 *      `DbDemoOrderCountReader` applies, because the column is free text);
 *   4. **`order58_orders` holds that order for that store** — the mirrored original order the test is
 *      about.
 *
 * Rule 4 is the one that matters: it is the store/order relationship, and it is what makes this a check
 * about the workflow rather than about a well-formed id.
 *
 * ## Administrators never reach this
 *
 * They are admitted by the administrator gate exactly as before, so their behaviour is unchanged and
 * costs no extra query. Only an operation request is scoped.
 *
 * ## Fails closed
 *
 * Any error — a dropped connection, a malformed id — returns false. A scope check that cannot prove
 * access must not grant it.
 *
 * Addressed by table name rather than through either module's repositories, because this file sits in the shared
 * namespace so that the authorization middleware can use it without naming the audio module, which
 * `ModuleIsolationTest` forbids outside that module. The same idiom the audio module's own
 * `DbDemoOrderCountReader` already uses to read Order Testing's tables.
 */
final readonly class OrderTestingJobScope
{
    private const JOBS = '{{%audio_transcription_jobs}}';
    private const CONVERSATIONS = '{{%audio_conversations}}';
    private const ORDERS = '{{%order58_orders}}';
    private const STORES = '{{%order58_stores}}';

    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @param string $jobPublicId the `{publicId}` every audio job route is keyed on
     */
    public function admitsJob(string $jobPublicId): bool
    {
        // Cheap shape check first: the route pattern already constrains this, but this class is callable
        // from anywhere and must not hand a stray value to the database.
        if (preg_match('/\A[0-9a-f]{32}\z/', $jobPublicId) !== 1) {
            return false;
        }

        try {
            $row = (new Query($this->connection))
                ->select(['c.store_source_id', 'c.order_id'])
                ->from(['j' => self::JOBS])
                ->innerJoin(['c' => self::CONVERSATIONS], 'c.id = j.conversation_id')
                ->where(['j.public_id' => $jobPublicId])
                ->one();
        } catch (Throwable) {
            return false;
        }

        if (!is_array($row)) {
            return false;
        }

        $storeSourceId = $row['store_source_id'] ?? null;
        $orderId = $row['order_id'] ?? null;

        if ($storeSourceId === null || $orderId === null) {
            return false;
        }

        // Free-text column, so anything that is not a plain positive integer cannot name a mirrored
        // order and is refused rather than cast.
        if (preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $orderId) !== 1) {
            return false;
        }

        return $this->storeExists((int) $storeSourceId)
            && $this->orderExists((int) $storeSourceId, (int) $orderId);
    }

    private function storeExists(int $storeSourceId): bool
    {
        try {
            return (new Query($this->connection))
                ->from(self::STORES)
                ->where(['source_id' => $storeSourceId])
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** The mirrored original order this test is about, scoped to its own store. */
    private function orderExists(int $storeSourceId, int $sourceOrderId): bool
    {
        try {
            return (new Query($this->connection))
                ->from(self::ORDERS)
                ->where(['account_id' => $storeSourceId, 'source_order_id' => $sourceOrderId])
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
