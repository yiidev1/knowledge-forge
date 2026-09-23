<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\GroupKey;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\StoreOrderGroup;
use App\AudioToText\Domain\StoreOrderGroupRepositoryInterface;
use App\AudioToText\Domain\StoreRecordingSlot;
use App\AudioToText\Domain\TranscriptionProvider;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsRendition;
use App\AudioToText\Domain\Tts\TtsRenditionRepositoryInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function array_map;
use function array_slice;
use function is_string;
use function usort;

use const SORT_DESC;

/**
 * A store's orders, in a fixed number of queries.
 *
 * ## Five queries, whatever is on the page
 *
 * ```
 * 1. count distinct group keys                     the pager
 * 2. the page of group keys, newest activity first  LIMIT/OFFSET lands HERE, on groups
 * 3. the conversations belonging to those keys
 * 4. every job of those conversations               one IN(), never one query per row
 * 5. every rendition of those jobs                  the existing batched forJobs()
 * ```
 *
 * A page of twenty orders costs exactly what a page of one costs. That is the property worth
 * protecting when editing this file: the moment a loop contains a query, a store with real history
 * starts paying for every row it shows.
 *
 * ## Why this does not reuse the conversation repository's child loader
 *
 * `DbAudioConversationRepository::childrenFor()` does batch — but it is private, and its projection is
 * built for the conversion pages: no `retained_audio_path`, no way to tell whether a transcript exists.
 * This page needs both, and needs them **without** selecting the transcript itself. So query 4 below is
 * its own projection of flags, and the two repositories stay independent rather than one growing a
 * second shape for the other's benefit.
 *
 * ## What is deliberately not selected
 *
 * `transcript`, `speaker_segments`, `reviewed_segments` and both reviewed text columns. A listing shows
 * that a transcript exists; the modals fetch what it says. Selecting the bodies here would put every
 * transcript on the store page for rows nobody opened.
 */
final readonly class DbStoreOrderGroupRepository implements StoreOrderGroupRepositoryInterface
{
    private const CONVERSATIONS = '{{%audio_conversations}}';
    private const JOBS = '{{%audio_transcription_jobs}}';

    public function __construct(
        private ConnectionInterface $connection,
        private TtsRenditionRepositoryInterface $renditions,
    ) {}

    public function pageFor(int $storeSourceId, int $limit, int $offset = 0): array
    {
        /** @var list<array<string, mixed>> $keyRows */
        $keyRows = (new Query($this->connection))
            ->select([
                'group_key' => new Expression(GroupKey::sqlExpression('c')),
                'latest_id' => new Expression('MAX(c.id)'),
            ])
            ->from(['c' => self::CONVERSATIONS])
            ->where(['c.store_source_id' => $storeSourceId])
            ->groupBy(new Expression(GroupKey::sqlExpression('c')))
            // By the newest conversation in each group, so an order rises when anything is added to it.
            ->orderBy(['latest_id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        if ($keyRows === []) {
            return [];
        }

        /** @var list<string> $keys */
        $keys = array_map(static fn(array $row): string => (string) $row['group_key'], $keyRows);

        return $this->build($storeSourceId, $keys);
    }

    public function countFor(int $storeSourceId): int
    {
        return (int) (new Query($this->connection))
            ->from(['c' => self::CONVERSATIONS])
            ->where(['c.store_source_id' => $storeSourceId])
            ->count('DISTINCT ' . GroupKey::sqlExpression('c'));
    }

    public function groupFor(int $storeSourceId, GroupKey $key): ?StoreOrderGroup
    {
        // The store is in the WHERE, so a key naming another store's conversation matches nothing here
        // and the caller answers 404 — the same answer an unknown key gets.
        $groups = $this->build($storeSourceId, [$key->value]);

        return $groups[0] ?? null;
    }

    /**
     * Queries 3, 4 and 5, and the assembly.
     *
     * @param list<string> $keys
     *
     * @return list<StoreOrderGroup>
     */
    private function build(int $storeSourceId, array $keys): array
    {
        /** @var list<array<string, mixed>> $conversationRows */
        $conversationRows = (new Query($this->connection))
            ->select([
                'id' => 'c.id',
                'public_id' => 'c.public_id',
                'mode' => 'c.mode',
                'recording_type' => 'c.recording_type',
                'order_id' => 'c.order_id',
                'created_at' => 'c.created_at',
                'group_key' => new Expression(GroupKey::sqlExpression('c')),
            ])
            ->from(['c' => self::CONVERSATIONS])
            ->where(['c.store_source_id' => $storeSourceId])
            // Operator form, because the key is an expression rather than a column: a `[$expr => …]`
            // hash would ask PHP to use an object as an array key.
            ->andWhere(['IN', new Expression(GroupKey::sqlExpression('c')), $keys])
            ->orderBy(['c.id' => SORT_DESC])
            ->all();

        if ($conversationRows === []) {
            return [];
        }

        $jobRows = $this->jobsFor(array_map(static fn(array $r): int => (int) $r['id'], $conversationRows));

        $renditions = $jobRows === []
            ? []
            : $this->renditions->forJobs(array_map(static fn(array $r): int => (int) $r['id'], $jobRows));

        // Jobs indexed by their conversation, so assembly below is array lookups rather than searches.
        $jobsByConversation = [];
        foreach ($jobRows as $row) {
            $jobsByConversation[(int) $row['conversation_id']][] = $row;
        }

        $byKey = [];
        foreach ($conversationRows as $row) {
            $byKey[(string) $row['group_key']][] = $row;
        }

        $groups = [];
        // Keyed order is the page order; rebuilding from $keys keeps it rather than trusting a hash map.
        foreach ($keys as $key) {
            $rows = $byKey[$key] ?? null;

            if ($rows === null) {
                continue;
            }

            $groups[] = $this->group($key, $rows, $jobsByConversation, $renditions);
        }

        return $groups;
    }

    /**
     * Query 4: every job of the visible conversations, as flags rather than content.
     *
     * @param list<int> $conversationIds
     *
     * @return list<array<string, mixed>>
     */
    private function jobsFor(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->select([
                'id' => 'j.id',
                'conversation_id' => 'j.conversation_id',
                'public_id' => 'j.public_id',
                'source_role' => 'j.source_role',
                'status' => 'j.status',
                'transcription_provider' => 'j.transcription_provider',
                'original_filename' => 'j.original_filename',
                'duration_seconds' => 'j.duration_seconds',
                'roles_confirmed_at' => 'j.roles_confirmed_at',
                // Existence, not content: the bodies stay in the database until a modal asks.
                'has_original' => new Expression('(j.retained_audio_path IS NOT NULL)'),
                'has_transcript' => new Expression("(j.transcript IS NOT NULL AND j.transcript <> '')"),
                'has_segments' => new Expression(
                    '(j.speaker_segments IS NOT NULL OR j.reviewed_segments IS NOT NULL)',
                ),
            ])
            ->from(['j' => self::JOBS])
            ->where(['j.conversation_id' => $conversationIds])
            ->orderBy(['j.id' => 'ASC'])
            ->all();

        return $rows;
    }

    /**
     * One group, assembled from rows already in memory.
     *
     * @param non-empty-list<array<string, mixed>>     $conversationRows    newest first
     * @param array<int, list<array<string, mixed>>>   $jobsByConversation
     * @param array<int, array<string, TtsRendition>>  $renditions
     */
    private function group(
        string $key,
        array $conversationRows,
        array $jobsByConversation,
        array $renditions,
    ): StoreOrderGroup {
        $slots = ['MIXED' => [], 'CALLER' => [], 'CALLEE' => []];
        $legacySeparate = [];
        $orderId = null;
        $latest = null;

        foreach ($conversationRows as $row) {
            $conversationId = (int) $row['id'];
            $mode = ConversationMode::fromStorage((string) $row['mode']) ?? ConversationMode::Common;
            $createdAt = DbDateTime::parse((string) $row['created_at']);
            $orderId ??= $this->nullableString($row['order_id'] ?? null);
            $latest = $latest === null || $createdAt > $latest ? $createdAt : $latest;

            foreach ($jobsByConversation[$conversationId] ?? [] as $jobRow) {
                $slot = $this->slot($row, $jobRow, $createdAt, $renditions, $mode);

                // A pre-recording-type pair is kept whole rather than pushed into a column it does not
                // belong in: its halves are Customer and Agent, which are not caller and callee.
                if ($mode === ConversationMode::Separate) {
                    $legacySeparate[] = $slot;

                    continue;
                }

                $slots[$slot->recordingType?->value ?? RecordingType::Mixed->value][] = $slot;
            }
        }

        return new StoreOrderGroup(
            GroupKey::fromInput($key) ?? GroupKey::forConversation((string) $conversationRows[0]['public_id']),
            $orderId,
            $latest,
            $this->primary($slots['MIXED']),
            $this->primary($slots['CALLER']),
            $this->primary($slots['CALLEE']),
            $legacySeparate,
        );
    }

    /**
     * The newest recording of one type, carrying the rest as its history.
     *
     * Nothing is discarded: an order uploaded twice keeps both, and the older one stays reachable.
     *
     * @param list<StoreRecordingSlot> $found
     */
    private function primary(array $found): ?StoreRecordingSlot
    {
        if ($found === []) {
            return null;
        }

        usort($found, static fn(StoreRecordingSlot $a, StoreRecordingSlot $b): int
            => $b->uploadedAt <=> $a->uploadedAt);

        $primary = $found[0];

        return new StoreRecordingSlot(
            $primary->conversationPublicId,
            $primary->jobPublicId,
            $primary->recordingType,
            $primary->sourceRole,
            $primary->status,
            $primary->provider,
            $primary->durationSeconds,
            $primary->uploadedAt,
            $primary->originalFilename,
            $primary->hasOriginalAudio,
            $primary->hasTranscript,
            $primary->hasSegments,
            $primary->rolesConfirmed,
            $primary->rendition,
            array_slice($found, 1),
        );
    }

    /**
     * @param array<string, mixed>                    $conversationRow
     * @param array<string, mixed>                    $jobRow
     * @param array<int, array<string, TtsRendition>> $renditions
     */
    private function slot(
        array $conversationRow,
        array $jobRow,
        DateTimeImmutable $createdAt,
        array $renditions,
        ConversationMode $mode,
    ): StoreRecordingSlot {
        $jobId = (int) $jobRow['id'];
        $role = SourceRole::fromStorage($this->nullableString($jobRow['source_role'] ?? null))
            ?? SourceRole::Common;

        // The mixed output is the only one a common recording has, and it is the one the column plays.
        $rendition = $renditions[$jobId][TtsOutputType::Mixed->value] ?? null;

        return new StoreRecordingSlot(
            (string) $conversationRow['public_id'],
            (string) $jobRow['public_id'],
            // A row that predates recording types is a COMMON upload, which is what "Common / Mixed"
            // has always meant on this page — normalised here, never written back to the database. A
            // SEPARATE pair gets none: its halves are roles, and no recording type describes them.
            $mode === ConversationMode::Separate
                ? null
                : RecordingType::fromStorage($this->nullableString($conversationRow['recording_type'] ?? null))
                    ?? RecordingType::Mixed,
            $role,
            JobStatus::tryFrom((string) $jobRow['status']) ?? JobStatus::QUEUED,
            TranscriptionProvider::fromStorage(
                $this->nullableString($jobRow['transcription_provider'] ?? null),
            ) ?? TranscriptionProvider::Whisper,
            $jobRow['duration_seconds'] === null ? null : (float) $jobRow['duration_seconds'],
            $createdAt,
            (string) $jobRow['original_filename'],
            (int) $jobRow['has_original'] === 1,
            (int) $jobRow['has_transcript'] === 1,
            (int) $jobRow['has_segments'] === 1,
            $this->nullableString($jobRow['roles_confirmed_at'] ?? null) !== null,
            $rendition,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
