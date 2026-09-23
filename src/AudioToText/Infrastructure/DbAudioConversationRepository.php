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
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
