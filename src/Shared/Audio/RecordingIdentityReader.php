<?php

declare(strict_types=1);

namespace App\Shared\Audio;

use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function ctype_digit;
use function is_array;
use function preg_match;

/**
 * Which store, order and channel a recording belongs to — read by table name, from anywhere.
 *
 * ## Why this is in Shared rather than in the audio module
 *
 * The caller is an agent route and an Order Testing reader, and neither may name the audio module's
 * namespace: the isolation test refuses any file outside that module which does — including, as this
 * docblock found out, a comment that merely spells it. The established answer is a Shared reader over
 * the table names, which is exactly what {@see \App\Shared\Web\Actor\PracticeStoreScope} already
 * does for the same two tables and for the same reason.
 *
 * ## Why the caller must not pass these in
 *
 * A tracking request names a recording. If it also named the store and order it belonged to, a request
 * could file one store's listening under another store's order — and the reporting would show it there
 * for ever. So the identity is looked up from the one id the route carries, and the body is never
 * consulted about it.
 */
final readonly class RecordingIdentityReader
{
    private const JOBS = '{{%audio_transcription_jobs}}';
    private const CONVERSATIONS = '{{%audio_conversations}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function find(string $jobPublicId): ?RecordingIdentity
    {
        if (preg_match('/\A[0-9a-f]{32}\z/', $jobPublicId) !== 1) {
            return null;
        }

        try {
            $row = (new Query($this->connection))
                ->select([
                    'store_source_id' => 'c.store_source_id',
                    'order_id' => 'c.order_id',
                    'recording_type' => 'c.recording_type',
                ])
                ->from(['j' => self::JOBS])
                ->innerJoin(['c' => self::CONVERSATIONS], 'c.id = j.conversation_id')
                ->where(['j.public_id' => $jobPublicId])
                ->one();
        } catch (Throwable) {
            return null;
        }

        if (!is_array($row) || $row['store_source_id'] === null) {
            return null;
        }

        $orderId = $row['order_id'];

        return new RecordingIdentity(
            jobPublicId: $jobPublicId,
            storeSourceId: (int) $row['store_source_id'],
            // `audio_conversations.order_id` is free text — an operator types it at upload — so it is
            // a number here only when it actually is one. The same digits-only rule the three existing
            // readers of this column apply.
            sourceOrderId: $orderId !== null && ctype_digit((string) $orderId) ? (int) $orderId : null,
            recordingType: $row['recording_type'] === null ? null : (string) $row['recording_type'],
        );
    }
}
