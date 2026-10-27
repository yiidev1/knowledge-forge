<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\GroupKey;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\OrderGroupFilter;
use App\AudioToText\Domain\OrderListingMode;
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
use App\Shared\Web\Actor\PracticeStoreScope;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function implode;
use function is_numeric;
use function is_string;

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

    /**
     * Named, not joined, and read by nothing else here.
     *
     * The topic a source order is practised under belongs to another module. This repository does not
     * depend on it: it narrows its own rows with a correlated `EXISTS` against the table by name, the
     * same way {@see DbTopicAssignmentReader} and {@see DbDemoOrderCountReader} read across. A join
     * would also multiply rows per order, which is the one thing a group query must not do.
     */
    private const TOPIC_ASSIGNMENTS = '`practice_order_topics`';

    /**
     * The original orders, named the same way and for the same reason.
     *
     * This repository lists a store's orders, and an order exists whether or not anything was recorded
     * against it — so the orders table is one of the two populations a listing can be a list of. It is
     * still read by name rather than through the Order58 module: nothing outside that module may name
     * its code.
     *
     * It is the primary table in {@see OrderListingMode::CaseOrderPrimary} — which the practice
     * surfaces always run under, see {@see modeFor()} — and is not read at all in the other mode.
     */
    private const ORDERS = '{{%order58_orders}}';

    public function __construct(
        private ConnectionInterface $connection,
        private TtsRenditionRepositoryInterface $renditions,
        /**
         * Which population this listing is a list OF. See {@see OrderListingMode}.
         *
         * Injected once, from one setting, and read nowhere else in the application — so Manage
         * Orders, Practice Orders and the audio store page cannot drift apart, and flipping the
         * setting needs no change to any query, action or template.
         */
        private OrderListingMode $mode = OrderListingMode::CaseOrderPrimary,
    ) {}

    /**
     * One page of a store's rows, from whichever population the mode names.
     *
     * ## One query per mode, never a union of both
     *
     * In {@see OrderListingMode::RecordingPrimary} this is the query these pages have always used:
     * conversations grouped by {@see GroupKey}, newest activity first. Not a variant of it — the same
     * SQL, so every row appears exactly where it appears today.
     *
     * In {@see OrderListingMode::CaseOrderPrimary} the primary table is the orders mirror instead:
     * one row per imported order, newest order first, and the recordings are then attached to those
     * rows by the same `build()` that attaches them in the other mode.
     *
     * A union of the two was the obvious shape and is the wrong one. It would have to invent a sort
     * key meaning the same thing for a conversation id and for an order, and "how many rows are
     * there" would become two numbers to reconcile — which is how a pager starts offering pages that
     * come back empty. One primary table per mode means the count is `COUNT(*)` of that table's
     * matching rows and the paging is its own `LIMIT`.
     *
     * ## Several recordings of one order are still one row, in both modes
     *
     * In recording-primary mode because the query groups by key, which is what it always did. In
     * case-order-primary mode because the primary table has one row per order by construction —
     * `order58_orders` is unique on `(account_id, source_order_id)` — so there is nothing for a second
     * recording to duplicate.
     */
    public function pageFor(
        int $storeSourceId,
        int $limit,
        int $offset = 0,
        ?OrderGroupFilter $filter = null,
        ?OrderListingMode $mode = null,
    ): array {
        if (!$this->modeFor($filter, $mode)->listsOrdersWithoutRecordings()) {
            $keys = $this->recordingKeys($storeSourceId, $limit, $offset, $filter);

            return $keys === [] ? [] : $this->build($storeSourceId, $keys);
        }

        $keys = $this->orderKeys($storeSourceId, $limit, $offset, $filter);

        if ($keys === []) {
            return [];
        }

        $built = [];

        foreach ($this->build($storeSourceId, $keys) as $group) {
            $built[$group->key->value] = $group;
        }

        /*
         * The orders on this page that have no recording, built from what the orders table knows.
         *
         * `build()` is left exactly as it is — it answers "assemble the recordings behind these keys"
         * and is also what `groupFor()` calls for the row modals, which must keep returning nothing
         * for a row that has no recording to show. A row with no recordings is not a recording this
         * page failed to find; it is an order, and it is assembled from the order.
         */
        $missing = [];

        foreach ($keys as $key) {
            if (!isset($built[$key])) {
                $missing[] = $key;
            }
        }

        $empty = $missing === [] ? [] : $this->emptyGroups($storeSourceId, $missing);

        // Rebuilt from $keys rather than from either map, so the page order is the query's order.
        $groups = [];

        foreach ($keys as $key) {
            $group = $built[$key] ?? $empty[$key] ?? null;

            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    public function countFor(
        int $storeSourceId,
        ?OrderGroupFilter $filter = null,
        ?OrderListingMode $mode = null,
    ): int {
        return $this->modeFor($filter, $mode)->listsOrdersWithoutRecordings()
            ? $this->countOrders($storeSourceId, $filter)
            : $this->countRecordings($storeSourceId, $filter);
    }

    /**
     * The mode this call runs under, which is not always the configured one.
     *
     * **The practice surfaces require an order AND something to listen to, whichever mode is
     * configured,** and that is enforced here rather than trusted to a caller. `requireLinkedOrder` is
     * their eligibility predicate: the order must exist in this store, and it must have either an
     * uploaded recording that still holds its audio or a current Case Order call recording link.
     *
     * It is answered from the orders table, narrowed by {@see PracticeStoreScope::orderSql()} — the
     * same rule the agent's store list and the middleware's object checks are built from, one level
     * down. It used to force the recording-primary query instead, which could only ever see uploaded
     * audio: an order whose recording arrives as a Case Order link has no row in `audio_conversations`
     * at all, and would have been hidden however playable it was. Driving the list from the orders also
     * gives it the order pages' own sort, newest order first.
     *
     * ## The order of precedence, and why it is this way round
     *
     * ```
     * 1. requireLinkedOrder  the practice predicate. Beats everything, including an explicit $asked.
     * 2. $asked              a surface whose subject is settled rather than configurable.
     * 3. $this->mode         ORDER58_CASE_ORDER_PRIMARY.
     * ```
     *
     * The practice predicate is first because it is authorization: a caller must not be able to widen
     * it by asking for a mode, even by accident.
     */
    private function modeFor(?OrderGroupFilter $filter, ?OrderListingMode $asked = null): OrderListingMode
    {
        if ($filter !== null && $filter->requireLinkedOrder) {
            return OrderListingMode::CaseOrderPrimary;
        }

        return $asked ?? $this->mode;
    }

    /**
     * Recording-primary keys: the query every one of these pages used before case orders existed.
     *
     * @return list<string>
     */
    private function recordingKeys(int $storeSourceId, int $limit, int $offset, ?OrderGroupFilter $filter): array
    {
        $query = (new Query($this->connection))
            ->select([
                'group_key' => new Expression(GroupKey::sqlExpression('c')),
                'latest_id' => new Expression('MAX(c.id)'),
            ])
            ->from(['c' => self::CONVERSATIONS])
            ->where(['c.store_source_id' => $storeSourceId]);

        /** @var list<array<string, mixed>> $keyRows */
        $keyRows = $this->narrow($query, $filter)
            ->groupBy(new Expression(GroupKey::sqlExpression('c')))
            // By the newest conversation in each group, so an order rises when anything is added to it.
            ->orderBy(['latest_id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(static fn(array $row): string => (string) $row['group_key'], $keyRows);
    }

    private function countRecordings(int $storeSourceId, ?OrderGroupFilter $filter): int
    {
        $query = (new Query($this->connection))
            ->from(['c' => self::CONVERSATIONS])
            ->where(['c.store_source_id' => $storeSourceId]);

        return (int) $this->narrow($query, $filter)
            ->count('DISTINCT ' . GroupKey::sqlExpression('c'));
    }

    /**
     * Case-order-primary keys: every imported order for this store, newest first.
     *
     * Every order, not only the ones with nothing recorded — the recordings are attached afterwards.
     * So an order with three recordings is one row here and one row on the page.
     *
     * Ordered by the provider's own time for the order with the order number breaking ties, which
     * makes the sequence stable across pages even for orders the provider timestamped identically. An
     * unstable sort would show one order twice and skip another, which on a practice catalogue reads
     * as missing data rather than as paging.
     *
     * This is a different sort from the other mode's, and it has to be: "newest recording activity"
     * is not a fact an order without a recording has. The intent — newest first — is the same.
     *
     * @return list<string>
     */
    private function orderKeys(int $storeSourceId, int $limit, int $offset, ?OrderGroupFilter $filter): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->narrowOrders($this->ordersOf($storeSourceId), $filter)
            ->select(['source_order_id' => 'o.source_order_id'])
            ->orderBy(['o.source_created_at' => SORT_DESC, 'o.source_order_id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(
            static fn(array $row): string => GroupKey::forOrder((string) $row['source_order_id'])->value,
            $rows,
        );
    }

    private function countOrders(int $storeSourceId, ?OrderGroupFilter $filter): int
    {
        // COUNT(*) of a table that is unique on (account_id, source_order_id): one row per order, so
        // the total cannot be inflated by an order that was recorded more than once.
        return (int) $this->narrowOrders($this->ordersOf($storeSourceId), $filter)->count();
    }

    private function ordersOf(int $storeSourceId): Query
    {
        return (new Query($this->connection))
            ->from(['o' => self::ORDERS])
            ->where(['o.account_id' => $storeSourceId]);
    }

    /**
     * The topic filter, as the order-primary query expresses it.
     *
     * The same two clauses {@see narrow()} applies, correlated straight onto the order's own integer
     * columns: this query reads the orders table, where the order id is a `BIGINT` rather than the
     * free text a conversation carries, so there is nothing to guard or cast.
     *
     * `requireLinkedOrder` is applied here too, and only here: {@see modeFor()} routes every filter
     * carrying it to this query, and the predicate is {@see PracticeStoreScope::orderSql()} rather
     * than a copy of it.
     */
    private function narrowOrders(Query $query, ?OrderGroupFilter $filter): Query
    {
        if ($filter !== null && $filter->requireLinkedOrder) {
            $query = $query->andWhere(new Expression(PracticeStoreScope::orderSql('o')));
        }

        if ($filter === null || !$filter->narrowsTopics()) {
            return $query;
        }

        $correlation = 'p.store_source_id = o.account_id AND p.source_order_id = o.source_order_id';

        $clauses = [];
        $params = [];

        if ($filter->topicIds !== []) {
            $placeholders = [];

            foreach ($filter->topicIds as $index => $topicId) {
                $name = ':orderTopicId' . $index;
                $placeholders[] = $name;
                $params[$name] = $topicId;
            }

            $clauses[] = 'EXISTS (SELECT 1 FROM ' . self::TOPIC_ASSIGNMENTS . ' p'
                . ' WHERE ' . $correlation
                . ' AND p.topic_id IN (' . implode(', ', $placeholders) . '))';
        }

        if ($filter->includeUnassigned) {
            $clauses[] = 'NOT EXISTS (SELECT 1 FROM ' . self::TOPIC_ASSIGNMENTS . ' p'
                . ' WHERE ' . $correlation . ')';
        }

        return $query->andWhere(new Expression('(' . implode(' OR ', $clauses) . ')', $params));
    }

    /**
     * One row per order that has no recording, keyed by its group key.
     *
     * `latestActivityAt` is the order's own time — the provider's if it sent one, this application's
     * import instant otherwise. The template prints it as "Added …", which is what it is: for a row
     * with no recording nothing was downloaded, and calling the import of an order a download would
     * describe something that never happened.
     *
     * `callTimeRaw` stays null for the same reason. It is when the *call* was recorded, and there is
     * no recording.
     *
     * @param list<string> $keys order keys, each known to have no conversation behind it
     *
     * @return array<string, StoreOrderGroup>
     */
    private function emptyGroups(int $storeSourceId, array $keys): array
    {
        $orderIds = [];

        foreach ($keys as $key) {
            $orderId = GroupKey::fromInput($key)?->orderId();

            if ($orderId !== null) {
                $orderIds[] = (int) $orderId;
            }
        }

        if ($orderIds === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = (new Query($this->connection))
            ->select([
                'source_order_id' => 'o.source_order_id',
                'source_created_at' => 'o.source_created_at',
                'created_at' => 'o.created_at',
            ])
            ->from(['o' => self::ORDERS])
            ->where(['o.account_id' => $storeSourceId, 'o.source_order_id' => $orderIds])
            ->all();

        $groups = [];

        foreach ($rows as $row) {
            $orderId = (string) $row['source_order_id'];
            $key = GroupKey::forOrder($orderId);

            $groups[$key->value] = new StoreOrderGroup($key, $orderId, $this->orderTime($row));
        }

        // An order that vanished between the two queries is simply absent from the map, and the caller
        // skips it rather than drawing a row with an invented timestamp.
        return $groups;
    }

    /**
     * When an order happened, preferring the provider's own instant.
     *
     * `source_created_at` is a unix timestamp from the provider and is what a reader means by "when
     * was this order". `created_at` is when this application first stored it, which is the honest
     * fallback for an order whose provider timestamp is missing or zero — never a guess.
     *
     * @param array<string, mixed> $row
     */
    private function orderTime(array $row): DateTimeImmutable
    {
        $source = $row['source_created_at'] ?? null;
        $unix = is_numeric($source) ? (int) $source : 0;

        if ($unix > 0) {
            return new DateTimeImmutable('@' . $unix);
        }

        return DbDateTime::parse((string) $row['created_at']);
    }


    /**
     * The filter, applied to whichever of the two queries above is asking.
     *
     * One method because the page and its pager must agree: a count taken without the filter the page
     * was fetched with reports orders the reader cannot see, and the pager then offers pages that come
     * back empty. Null is returned untouched, so an unfiltered call builds the SQL it always built.
     *
     * The correlation carries `store_source_id` as well as the order id, and that is not decoration:
     * `source_order_id` is unique only *within* a store (see the `order58_orders` migration), so
     * without it one merchant's topic row would match another merchant's identically numbered order.
     * The digits guard is the same one every other reader of this free-text column uses before casting.
     */
    private function narrow(Query $query, ?OrderGroupFilter $filter): Query
    {
        if ($filter === null) {
            return $query;
        }

        // Two independent narrowings, applied in order: which orders exist to be listed at all, then
        // which topic the reader asked for. Both land on the same query object, so the count and the
        // page cannot be taken under different halves of it.

        $correlation = 'p.store_source_id = c.store_source_id'
            . ' AND p.source_order_id = CAST(c.order_id AS UNSIGNED)';
        $numeric = "c.order_id REGEXP '^[1-9][0-9]{0,18}$'";

        // `requireLinkedOrder` never reaches this query: {@see modeFor()} sends it to the orders-driven
        // one, where {@see narrowOrders()} applies the practice predicate. The administrator surfaces
        // that do reach here list what was uploaded, including the broken rows, because seeing them is
        // how they get fixed.

        if (!$filter->narrowsTopics()) {
            // Eligibility only, with no topic narrowing asked for.
            return $query;
        }

        // OR across the chosen topics, and "not assigned" is one more choice in the same list rather
        // than a mode of its own — so "this topic or nothing yet" is one ordinary selection. Written as
        // EXISTS rather than a join because a join would multiply rows per order, which is the one
        // thing a query that pages by group must not do.
        $clauses = [];
        $params = [];

        if ($filter->topicIds !== []) {
            $placeholders = [];

            foreach ($filter->topicIds as $index => $topicId) {
                $name = ':topicId' . $index;
                $placeholders[] = $name;
                $params[$name] = $topicId;
            }

            $clauses[] = 'EXISTS (SELECT 1 FROM ' . self::TOPIC_ASSIGNMENTS . ' p'
                . ' WHERE ' . $correlation
                . ' AND p.topic_id IN (' . implode(', ', $placeholders) . '))';
        }

        if ($filter->includeUnassigned) {
            $clauses[] = 'NOT EXISTS (SELECT 1 FROM ' . self::TOPIC_ASSIGNMENTS . ' p'
                . ' WHERE ' . $correlation . ')';
        }

        return $query
            ->andWhere(new Expression($numeric))
            ->andWhere(new Expression('(' . implode(' OR ', $clauses) . ')', $params));
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
                'call_time_raw' => 'c.call_time_raw',
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
        $callTimeRaw = null;

        foreach ($conversationRows as $row) {
            $conversationId = (int) $row['id'];
            $mode = ConversationMode::fromStorage((string) $row['mode']) ?? ConversationMode::Common;
            $createdAt = DbDateTime::parse((string) $row['created_at']);

            // The call's own time, from whichever conversation of this order records one. They are all
            // recordings of the same call, so the first non-null answer is the answer — and a hand-made
            // upload sitting alongside imported ones has none, which must not blank it.
            $callTimeRaw ??= is_string($row['call_time_raw'] ?? null) && $row['call_time_raw'] !== ''
                ? (string) $row['call_time_raw']
                : null;
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
            $callTimeRaw,
        );
    }

    /**
     * The **current** recording of one type, carrying every other version as its history.
     *
     * Nothing is discarded: an order uploaded twice keeps both, and the earlier one stays reachable.
     *
     * ## Current means newest *finished*, not newest uploaded
     *
     * This is what makes replacing a recording safe. An administrator who replaces the caller side has
     * a working recording until the replacement is transcribed — and if the replacement fails, they
     * still do. Were "current" simply the newest row, the moment a replacement was queued the order
     * would start showing a recording with no transcript, no speakers and nothing to correct, and a
     * failed replacement would leave it that way for good.
     *
     * So a version becomes current by **completing**, and the switch is therefore the worker's own
     * `status` write: one row, one transaction, already atomic. There is no second pointer to update,
     * which is precisely why there is nothing here for two replacements to race over.
     *
     * ## Why the ordering is the id and not the timestamp
     *
     * `$found` arrives newest-first because {@see build()} orders conversations by `c.id DESC`, and
     * that is a total order the database maintains. `created_at` is only second-precision: two uploads
     * within one second compare equal, and a sort on equal keys decides nothing. It also means a
     * worker that finishes late cannot win — a replacement is newer or older by the id it was given
     * when it was uploaded, never by when its transcription happened to finish.
     *
     * @param list<StoreRecordingSlot> $found newest first, by conversation id
     */
    private function primary(array $found): ?StoreRecordingSlot
    {
        if ($found === []) {
            return null;
        }

        // Numbered from the far end, so the earliest upload is v1 and stays v1 whatever arrives above
        // it. `$found` is newest-first, so the count itself is the newest one's number.
        $total = count($found);
        $found = array_map(
            static fn(int $index, StoreRecordingSlot $slot): StoreRecordingSlot
                => $slot->withVersion($total - $index),
            array_keys($found),
            $found,
        );

        $primary = null;

        foreach ($found as $slot) {
            if ($slot->status === JobStatus::COMPLETED) {
                $primary = $slot;

                break;
            }
        }

        // Nothing has finished yet — a first upload still processing, or one that failed. The newest is
        // shown so the order reports what is actually happening to it rather than an empty cell.
        $primary ??= $found[0];

        // Everything else, still newest-first: an in-flight replacement is in here too, which is how
        // the Manage Audio dialog can say a replacement is under way without asking a second question.
        $found = array_values(array_filter(
            $found,
            static fn(StoreRecordingSlot $slot): bool => $slot !== $primary,
        ));

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
            $found,
            $primary->version,
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
