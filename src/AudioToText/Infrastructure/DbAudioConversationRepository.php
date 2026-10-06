<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\AudioConversation;
use App\AudioToText\Domain\AudioConversationChild;
use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\ProcessingStage;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\TranscriptionProvider;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_column;
use function count;
use function is_string;

use const SORT_ASC;
use const SORT_DESC;

/**
 * Conversations, and the children that belong to them.
 *
 * The store-facing screens count and page over *this* table, so one paired upload is one row, one page
 * slot and one count. The job repository stays the technical view of the queue, where the same upload
 * is legitimately two rows.
 *
 * Children are fetched in a single follow-up query keyed by parent, not one query per conversation:
 * a page of 20 conversations would otherwise be 21 round trips for a list that shows a filename and a
 * status.
 */
final readonly class DbAudioConversationRepository implements AudioConversationRepositoryInterface
{
    private const TABLE = '{{%audio_conversations}}';
    private const JOBS = '{{%audio_transcription_jobs}}';
    private const ADMINS = '{{%admin_users}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function create(
        string $publicId,
        ?int $storeSourceId,
        ConversationMode $mode,
        int $uploadedByAdminId,
        DateTimeImmutable $createdAt,
        bool $generateAiAudio = false,
        ?RecordingType $recordingType = null,
        ?string $orderId = null,
        ?string $callSessionId = null,
        ?string $callTimeRaw = null,
    ): int {
        $this->connection->createCommand()->insert(self::TABLE, [
            'public_id' => $publicId,
            'store_source_id' => $storeSourceId,
            'mode' => $mode->value,
            // The upload card, as chosen. NULL when none was named, which is what the column means —
            // no value is substituted here, because "mixed" is a claim and absence is not.
            'recording_type' => $recordingType?->value,
            // NULL rather than '' or 0 for an upload with no order: both of those are values that look
            // like answers, and the history would have to tell them apart from a real one.
            'order_id' => $orderId,
            // The provider's call session id, or NULL for an upload made by hand — which is every
            // upload until the importer runs. NULL means "not known to belong to a call", and the
            // derived views read it as "show this recording on its own".
            'call_session_id' => $callSessionId,
            // Exactly what the provider sent. Not parsed, not converted, not given a timezone it does
            // not carry — see the column's migration.
            'call_time_raw' => $callTimeRaw,
            'uploaded_by_admin_id' => $uploadedByAdminId,
            'created_at' => DbDateTime::format($createdAt),
            // Written once, at upload, and never rewritten. The generation itself is decided later and
            // elsewhere; this only records what was asked for.
            'generate_ai_audio' => $generateAiAudio ? 1 : 0,
        ])->execute();

        return (int) $this->connection->getLastInsertID();
    }

    public function findByPublicId(string $publicId): ?AudioConversation
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->baseQuery()->where(['c.public_id' => $publicId])->one();

        if ($row === null) {
            return null;
        }

        $children = $this->childrenFor([(int) $row['id']]);

        return $this->hydrate($row, $children[(int) $row['id']] ?? []);
    }

    public function forStore(int $storeSourceId, int $limit, int $offset = 0): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->baseQuery()
            ->where(['c.store_source_id' => $storeSourceId])
            ->orderBy(['c.id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        if ($rows === []) {
            return [];
        }

        $children = $this->childrenFor(array_column($rows, 'id'));

        $conversations = [];
        foreach ($rows as $row) {
            $conversations[] = $this->hydrate($row, $children[(int) $row['id']] ?? []);
        }

        return $conversations;
    }

    public function countForStore(int $storeSourceId): int
    {
        return (int) (new Query($this->connection))
            ->from(['c' => self::TABLE])
            ->where(['c.store_source_id' => $storeSourceId])
            ->count();
    }

    public function recordingTypeFor(int $conversationId): ?RecordingType
    {
        $value = (new Query($this->connection))
            ->select('recording_type')
            ->from(['c' => self::TABLE])
            ->where(['c.id' => $conversationId])
            ->limit(1)
            ->scalar();

        // The column is nullable and a missing row returns false. Both mean "no declared type", which
        // the read model treats as a conversation — the state every pre-existing row is in.
        return is_string($value) ? RecordingType::fromStorage($value) : null;
    }

    public function callSessionFor(int $conversationId): ?string
    {
        $value = (new Query($this->connection))
            ->select('call_session_id')
            ->from(['c' => self::TABLE])
            ->where(['c.id' => $conversationId])
            ->limit(1)
            ->scalar();

        // Nullable column, and a missing row returns false. Both mean "not known to belong to a call".
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function confirmedMixedJobIdForCallSession(int $storeSourceId, string $callSessionId): ?int
    {
        // Two rows are fetched to answer a question about one. LIMIT 1 would report the first of several
        // as though it were the only one, which is the failure this method exists to make impossible:
        // the same recording uploaded twice is a normal state, and it must read as "no single answer"
        // rather than as whichever row the optimiser happened to return.
        $rows = (new Query($this->connection))
            ->select(['j.id'])
            ->from(['c' => self::TABLE])
            ->innerJoin(['j' => self::JOBS], 'j.conversation_id = c.id')
            ->where([
                'c.store_source_id' => $storeSourceId,
                'c.call_session_id' => $callSessionId,
                'c.recording_type' => RecordingType::Mixed->value,
            ])
            ->andWhere(['not', ['j.roles_confirmed_at' => null]])
            ->limit(2)
            ->column();

        return count($rows) === 1 ? (int) $rows[0] : null;
    }

    public function channelJobIdsForCallSession(int $storeSourceId, string $callSessionId): array
    {
        return $this->channelJobIds([
            'c.store_source_id' => $storeSourceId,
            'c.call_session_id' => $callSessionId,
        ]);
    }

    public function channelJobIdsForOrder(int $storeSourceId, string $orderId): array
    {
        return $this->channelJobIds(['c.store_source_id' => $storeSourceId, 'c.order_id' => $orderId]);
    }

    public function orderIdFor(int $conversationId): ?string
    {
        $value = (new Query($this->connection))
            ->select('order_id')
            ->from(self::TABLE)
            ->where(['id' => $conversationId])
            ->scalar();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The **current** caller and callee job, one per side, for whichever grouping column was named.
     *
     * ## Why one and not all of them
     *
     * Replacing a recording does not overwrite it: Update Audio writes a NEW conversation and a new job
     * for that side and leaves the old one standing, which is what makes a version history and a revert
     * possible at all. So a side that has been replaced once has two rows, and a caller asking "which
     * recording is the Customer side of this call" was being handed both.
     *
     * It had nowhere to go with that. {@see \App\AudioToText\Application\Combined\CombinedConversationReader}
     * refused the whole call as {@see \App\AudioToText\Domain\Speaker\CombinedConversationState::AmbiguousChildren}
     * — "more than one recording of the same side, and nothing here records which is authoritative" —
     * so a perfectly ordinary replacement took the combined conversation away until somebody deleted a
     * recording. Nothing was wrong with the data; the question was being asked of the wrong layer.
     *
     * ## Current means newest *finished*, not newest uploaded
     *
     * The same rule {@see \App\AudioToText\Infrastructure\DbStoreOrderGroupRepository::primary()} has
     * always applied on the store page, stated once more here because this is the other place that has
     * to answer it. A replacement that is still transcribing must not become the current recording the
     * moment it is queued — the one it replaces is still the one with words in it — so a version becomes
     * current by **completing**. Until then the previous recording is still the call's Customer side.
     *
     * Ordered by conversation id DESC so "newest" is the id the database issued, not a timestamp: two
     * uploads within one second compare equal on time, and a worker that finishes late cannot win a side
     * it was not given. With nothing finished, the newest row stands in, so a first upload still
     * processing is reported rather than the side looking absent.
     *
     * The return shape is unchanged — a list per side — so a caller that still wants to guard against
     * two keeps working. It is simply never given two now.
     *
     * @param array<string, mixed> $scope
     *
     * @return array<string, list<int>>
     */
    private function channelJobIds(array $scope): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->select([
                'id' => 'j.id',
                'recording_type' => 'c.recording_type',
                'status' => 'j.status',
            ])
            ->from(['c' => self::TABLE])
            ->innerJoin(['j' => self::JOBS], 'j.conversation_id = c.id')
            ->where($scope)
            ->andWhere([
                // The two sides, named explicitly. A mixed row is the caller of this method and an
                // untyped row predates recording types, so neither can be one side of a call.
                'c.recording_type' => [RecordingType::Caller->value, RecordingType::Callee->value],
            ])
            // Newest version of each side first, so the first row of a side is the fallback and the
            // first COMPLETED one is the answer.
            ->orderBy(['c.id' => SORT_DESC, 'j.id' => SORT_DESC])
            ->all();

        $newest = [];
        $current = [];

        foreach ($rows as $row) {
            $type = $row['recording_type'];

            if (!is_string($type)) {
                continue;
            }

            $newest[$type] ??= (int) $row['id'];

            if (!isset($current[$type]) && $row['status'] === JobStatus::COMPLETED->value) {
                $current[$type] = (int) $row['id'];
            }
        }

        $grouped = [];

        foreach ($newest as $type => $fallback) {
            $grouped[$type] = [$current[$type] ?? $fallback];
        }

        return $grouped;
    }

    public function unlinkedForCallSessionBackfill(int $limit): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->select([
                'id' => 'c.id',
                'store_source_id' => 'c.store_source_id',
                'recording_type' => 'c.recording_type',
                'original_filename' => 'j.original_filename',
            ])
            ->from(['c' => self::TABLE])
            ->innerJoin(['j' => self::JOBS], 'j.conversation_id = c.id')
            ->where(['c.call_session_id' => null])
            ->orderBy(['c.id' => SORT_ASC])
            ->limit($limit)
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'storeSourceId' => $row['store_source_id'] === null ? null : (int) $row['store_source_id'],
                'recordingType' => $this->nullableString($row['recording_type'] ?? null),
                'filename' => $this->nullableString($row['original_filename'] ?? null),
            ];
        }

        return $out;
    }

    public function recordCallSession(int $conversationId, string $callSessionId): bool
    {
        // `call_session_id IS NULL` in the WHERE, not just in the reading query above: between the read
        // and this write the importer may have linked the same row with the provider's own value, and
        // that value outranks one recovered from a filename.
        $affected = $this->connection->createCommand()->update(
            self::TABLE,
            ['call_session_id' => $callSessionId],
            ['id' => $conversationId, 'call_session_id' => null],
        )->execute();

        return $affected === 1;
    }

    public function storeSourceIdFor(int $conversationId): ?int
    {
        $value = (new Query($this->connection))
            ->select('store_source_id')
            ->from(['c' => self::TABLE])
            ->where(['c.id' => $conversationId])
            ->limit(1)
            ->scalar();

        // The column is nullable, and a missing row returns false. Both mean "no store page".
        return $value === null || $value === false ? null : (int) $value;
    }

    public function publicIdFor(int $conversationId): ?string
    {
        $value = (new Query($this->connection))
            ->select('public_id')
            ->from(['c' => self::TABLE])
            ->where(['c.id' => $conversationId])
            ->limit(1)
            ->scalar();

        // `false` is a missing row. The column is NOT NULL, so anything else is a real id.
        return $value === false || $value === null ? null : (string) $value;
    }

    public function generatesAiAudio(int $conversationId): bool
    {
        $value = (new Query($this->connection))
            ->select('generate_ai_audio')
            ->from(['c' => self::TABLE])
            ->where(['c.id' => $conversationId])
            ->limit(1)
            ->scalar();

        // A missing row returns false, which is also the answer: nothing to generate for a conversation
        // that is not there.
        return (int) ($value === false ? 0 : $value) === 1;
    }

    public function deleteChildless(): int
    {
        // One statement rather than a read-then-delete loop: the set is computed and removed inside
        // the same statement, so a job inserted between a scan and a delete cannot lose its parent.
        return $this->connection->createCommand(
            'DELETE c FROM ' . self::TABLE . ' c
             WHERE NOT EXISTS (
                 SELECT 1 FROM ' . self::JOBS . ' j WHERE j.conversation_id = c.id
             )',
        )->execute();
    }

    private function baseQuery(): Query
    {
        return (new Query($this->connection))
            ->select([
                'id' => 'c.id',
                'public_id' => 'c.public_id',
                'store_source_id' => 'c.store_source_id',
                'mode' => 'c.mode',
                'recording_type' => 'c.recording_type',
                'order_id' => 'c.order_id',
                'call_session_id' => 'c.call_session_id',
                'call_time_raw' => 'c.call_time_raw',
                'uploaded_by_admin_id' => 'c.uploaded_by_admin_id',
                'created_at' => 'c.created_at',
                'generate_ai_audio' => 'c.generate_ai_audio',
                'uploaded_by_username' => 'a.username',
            ])
            ->from(['c' => self::TABLE])
            // LEFT: the uploader foreign key is RESTRICT so the row should always be there, but a
            // missing administrator must cost a username, never the whole conversation.
            ->leftJoin(['a' => self::ADMINS], 'a.id = c.uploaded_by_admin_id');
    }

    /**
     * @param list<mixed> $conversationIds
     *
     * @return array<int, list<AudioConversationChild>>
     */
    private function childrenFor(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->select([
                'conversation_id',
                'public_id',
                'source_role',
                'status',
                'processing_stage',
                'original_filename',
                'duration_seconds',
                'error_message',
                'transcription_provider',
            ])
            ->from(self::JOBS)
            ->where(['conversation_id' => $conversationIds])
            // By id, which is creation order: a separate pair reads Customer then Agent, the order
            // they were uploaded in.
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $grouped = [];
        foreach ($rows as $row) {
            $status = JobStatus::tryFrom((string) $row['status']);
            $role = SourceRole::fromStorage($this->nullableString($row['source_role'] ?? null));

            if ($status === null || $role === null) {
                // A row predating the columns, or written by something that did not honour the CHECK.
                // Skipping it is better than inventing a role it never had.
                continue;
            }

            $grouped[(int) $row['conversation_id']][] = new AudioConversationChild(
                (string) $row['public_id'],
                $role,
                $status,
                ProcessingStage::tryFrom((string) ($row['processing_stage'] ?? '')),
                (string) $row['original_filename'],
                $row['duration_seconds'] === null ? null : (float) $row['duration_seconds'],
                $this->nullableString($row['error_message'] ?? null),
                // NULL — a job queued before providers were selectable — resolves to Whisper here, so
                // no template downstream has to know the legacy case existed.
                TranscriptionProvider::fromStorage($this->nullableString($row['transcription_provider'] ?? null))
                    ?? TranscriptionProvider::Whisper,
            );
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed>         $row
     * @param list<AudioConversationChild> $children
     */
    private function hydrate(array $row, array $children): AudioConversation
    {
        return new AudioConversation(
            (int) $row['id'],
            (string) $row['public_id'],
            $row['store_source_id'] === null ? null : (int) $row['store_source_id'],
            ConversationMode::fromStorage((string) $row['mode']) ?? ConversationMode::Common,
            (int) $row['uploaded_by_admin_id'],
            $this->nullableString($row['uploaded_by_username'] ?? null),
            DbDateTime::parse((string) $row['created_at']),
            $children,
            // MySQL hands a TINYINT back as an int or a numeric string depending on the driver's mood,
            // so the comparison is loose on purpose rather than relying on either.
            (int) ($row['generate_ai_audio'] ?? 0) === 1,
            // NULL — an upload made before the cards were told apart — stays null rather than becoming
            // MIXED, and the conversation falls back to its mode's label. See RecordingType.
            RecordingType::fromStorage($this->nullableString($row['recording_type'] ?? null)),
            $this->nullableString($row['order_id'] ?? null),
            $this->nullableString($row['call_session_id'] ?? null),
            $this->nullableString($row['call_time_raw'] ?? null),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
