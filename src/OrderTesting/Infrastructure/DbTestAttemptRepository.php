<?php

declare(strict_types=1);

namespace App\OrderTesting\Infrastructure;

use App\OrderTesting\Domain\AttemptStart;
use App\OrderTesting\Domain\AttemptStatus;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\TestAttempt;
use App\OrderTesting\Domain\TestAttemptRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function bin2hex;
use function count;
use function is_array;
use function random_bytes;

use const SORT_ASC;
use const SORT_DESC;

/**
 * Stores and reads who set out to test what.
 *
 * ## The one-open-attempt rule is settled by the database, not by a service
 *
 * {@see start()} runs its read and its insert inside one transaction with `SELECT … FOR UPDATE`. Of two
 * administrators clicking the same row in the same second, one takes the gap lock on the index range
 * and inserts; the other blocks, then reads the winner's row and is told whose it is. Checking in PHP
 * and then inserting would let both pass the check before either wrote, and both would be told they
 * held the slot — after which every demo order for that source order is attributed to a coin toss.
 *
 * ## Times are UTC, like everything else stored here
 *
 * `DATETIME` carries no zone, so the convention is the application's: store UTC, convert at the edge.
 * Reading a row back therefore pins the zone explicitly rather than taking the server's.
 */
final readonly class DbTestAttemptRepository implements TestAttemptRepositoryInterface
{
    private const TABLE = '{{%order_testing_attempts}}';
    private const ADMINS = '{{%admin_users}}';

    /**
     * Where the other two realms' names live.
     *
     * Order58's mirror, keyed by `admin_id` — the identity that survives a rebuild of the mirror, and
     * the same value {@see \App\OrderTesting\Domain\InitiatorType} documents as what an OPERATION or
     * AGENT row stores. Read by table name, like every other cross-module read in this module.
     */
    private const OPERATORS = '{{%order58_agents}}';

    /** The two statuses that still hold the slot. See {@see AttemptStatus::isOpen()}. */
    private const OPEN = [AttemptStatus::Started->value, AttemptStatus::Matched->value];

    public function __construct(private ConnectionInterface $connection) {}

    /**
     * The name behind an attempt, joined on the realm as well as the id.
     *
     * **`initiated_by_id` is one polymorphic column, not a pair.** The realms number their users
     * separately — `admin_users.id = 7` and Order58's `admin_id = 7` are different people — so a join
     * on the id alone renders whichever of them happens to exist, and an operation user's work appears
     * under an administrator's name. That was live behaviour before this was qualified; adding a third
     * realm would have multiplied it rather than caused it.
     *
     * Two LEFT joins and a COALESCE, exactly as `DbSegmentRevisionRepository` already does for the same
     * shape of column. LEFT so an attempt outlives the account that made it and still reports its own
     * existence rather than vanishing from the page.
     */
    private function withActorName(Query $query): Query
    {
        $admin = InitiatorType::Admin->value;
        $operation = InitiatorType::Operation->value;
        $agent = InitiatorType::Agent->value;

        return $query
            ->select(['a.*', 'admin_username' => new Expression('COALESCE(u.username, oa.username)')])
            ->leftJoin(
                ['u' => self::ADMINS],
                "u.id = a.initiated_by_id AND a.initiated_by_type = '" . $admin . "'",
            )
            ->leftJoin(
                ['oa' => self::OPERATORS],
                "oa.admin_id = a.initiated_by_id AND a.initiated_by_type IN ('"
                    . $operation . "', '" . $agent . "')",
            );
    }

    public function openFor(int $storeSourceId, int $sourceOrderId, DateTimeImmutable $now): ?TestAttempt
    {
        $row = $this->openQuery($storeSourceId, $sourceOrderId, $now)->one();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function start(
        int $storeSourceId,
        int $sourceOrderId,
        InitiatorType $type,
        int $initiatedById,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): AttemptStart {
        /** @var AttemptStart */
        return $this->connection->transaction(
            function () use ($storeSourceId, $sourceOrderId, $type, $initiatedById, $now, $expiresAt): AttemptStart {
                /*
                 * This person's own open attempt, if they already have one.
                 *
                 * Scoped to the actor rather than to the source order. The lock used to be
                 * order-wide — one person at a time, everybody else refused — which is no longer
                 * the business rule: several people may practise the same order at once, and each
                 * gets their own independently tracked attempt.
                 *
                 * What it still prevents is the same person stacking attempts by pressing twice, or
                 * by two tabs racing. `FOR UPDATE` is still the whole mechanism for that; without it
                 * this is a check-then-act race against yourself.
                 */
                $held = $this->openQuery($storeSourceId, $sourceOrderId, $now)
                    ->andWhere([
                        'a.initiated_by_type' => $type->value,
                        'a.initiated_by_id' => $initiatedById,
                    ])
                    ->for('UPDATE')
                    ->one();

                if (is_array($held)) {
                    return new AttemptStart($this->hydrate($held), false);
                }

                $stamp = self::stamp($now);

                $this->connection->createCommand()->insert(self::TABLE, [
                    'public_id' => bin2hex(random_bytes(16)),
                    'store_source_id' => $storeSourceId,
                    'source_order_id' => $sourceOrderId,
                    'initiated_by_type' => $type->value,
                    'initiated_by_id' => $initiatedById,
                    'status' => AttemptStatus::Started->value,
                    'started_at' => $stamp,
                    'expires_at' => self::stamp($expiresAt),
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ])->execute();

                $row = $this->openQuery($storeSourceId, $sourceOrderId, $now)
                    ->andWhere([
                        'a.initiated_by_type' => $type->value,
                        'a.initiated_by_id' => $initiatedById,
                    ])
                    ->one();

                if (!is_array($row)) {
                    // Only reachable if the row vanished between the insert and the read inside one
                    // transaction, which cannot happen; the guard is here so the return type is honest.
                    throw new RuntimeException('the attempt just created could not be read back');
                }

                return new AttemptStart($this->hydrate($row), true);
            },
        );
    }

    public function historyFor(
        int $storeSourceId,
        int $sourceOrderId,
        ?InitiatorType $onlyActorType = null,
        ?int $onlyActorId = null,
    ): array {
        $query = $this->withActorName((new Query($this->connection))->from(['a' => self::TABLE]))
            ->where(['a.store_source_id' => $storeSourceId, 'a.source_order_id' => $sourceOrderId]);

        /*
         * The authorization filter, applied to the QUERY.
         *
         * An agent may see their own attempts and nobody else's. Narrowing here rather than after
         * the fact means the rows never leave the database, so a template that forgot to filter — or
         * a future caller that renders the list differently — cannot leak somebody else's history.
         *
         * Both halves are required together: a realm without an id would match every agent, and an
         * id without a realm would match whichever administrator happens to share the number.
         */
        if ($onlyActorType !== null && $onlyActorId !== null) {
            $query = $query->andWhere([
                'a.initiated_by_type' => $onlyActorType->value,
                'a.initiated_by_id' => $onlyActorId,
            ]);
        }

        // Oldest first: Try 1 is the first attempt somebody made, and a late import must not renumber
        // the ones around it. `id` breaks ties, so two attempts in the same second stay deterministic.
        $rows = $query->orderBy(['a.started_at' => SORT_ASC, 'a.id' => SORT_ASC])->all();

        $attempts = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $attempts[] = $this->hydrate($row);
            }
        }

        return $attempts;
    }

    public function attemptCoveringInstant(
        int $storeSourceId,
        int $sourceOrderId,
        DateTimeImmutable $createdAt,
    ): ?TestAttempt {
        $at = self::stamp($createdAt);

        /*
         * Every attempt whose window contains the instant — and two are asked for on purpose.
         *
         * `limit(2)` rather than `limit(1)`: the point is to find out whether the evidence is
         * *unambiguous*, and a query that takes the first row can never tell the difference between
         * one candidate and three. Two rows is all it takes to know the answer is "more than one",
         * and more than one means Unmatched.
         *
         * The window is half-open — `started_at <= instant < expires_at` — so two back-to-back
         * attempts cannot both claim an order created exactly on the boundary.
         */
        $rows = $this->withActorName((new Query($this->connection))->from(['a' => self::TABLE]))
            ->where([
                'and',
                ['a.store_source_id' => $storeSourceId, 'a.source_order_id' => $sourceOrderId],
                ['<=', 'a.started_at', $at],
                ['>', 'a.expires_at', $at],
            ])
            ->limit(2)
            ->all();

        if (count($rows) !== 1 || !is_array($rows[0])) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    public function recordMatch(int $attemptId, int $demoOrderId, DateTimeImmutable $now): void
    {
        $stamp = self::stamp($now);

        // `matched_demo_order_id` and `matched_at` are written once — the FIRST result of this attempt.
        // The second and third demo orders of the same session still point at it from their own rows,
        // which is where the many-to-one relationship actually lives.
        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'status' => AttemptStatus::Matched->value,
                'matched_demo_order_id' => $demoOrderId,
                'matched_at' => $stamp,
                'updated_at' => $stamp,
            ],
            ['id' => $attemptId, 'matched_demo_order_id' => null],
        )->execute();

        // An attempt already matched still belongs to the same operator and still holds the slot; only
        // its first-result columns are settled. Nothing else needs writing.
    }

    public function expireOverdue(DateTimeImmutable $now): int
    {
        $stamp = self::stamp($now);

        return $this->connection->createCommand()->update(
            self::TABLE,
            ['status' => AttemptStatus::Expired->value, 'completed_at' => $stamp, 'updated_at' => $stamp],
            ['and', ['status' => self::OPEN], ['<=', 'expires_at', $stamp]],
        )->execute();
    }

    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->withActorName((new Query($this->connection))->from(['a' => self::TABLE]))
            ->where(['a.id' => $ids])
            ->all();

        $attempts = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $attempt = $this->hydrate($row);
                $attempts[$attempt->id] = $attempt;
            }
        }

        return $attempts;
    }

    private function openQuery(int $storeSourceId, int $sourceOrderId, DateTimeImmutable $now): Query
    {
        return $this->withActorName((new Query($this->connection))->from(['a' => self::TABLE]))
            ->where([
                'and',
                ['a.store_source_id' => $storeSourceId, 'a.source_order_id' => $sourceOrderId],
                ['a.status' => self::OPEN],
                ['>', 'a.expires_at', self::stamp($now)],
            ])
            // Newest first: there can only be one open attempt, but ordering makes the read
            // deterministic if a historical row ever violated that.
            ->orderBy(['a.id' => SORT_DESC])
            ->limit(1);
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): TestAttempt
    {
        return new TestAttempt(
            id: (int) $row['id'],
            publicId: (string) $row['public_id'],
            storeSourceId: (int) $row['store_source_id'],
            sourceOrderId: (int) $row['source_order_id'],
            initiatedByType: InitiatorType::from((string) $row['initiated_by_type']),
            initiatedById: (int) $row['initiated_by_id'],
            status: AttemptStatus::from((string) $row['status']),
            startedAt: self::time($row['started_at']),
            expiresAt: self::time($row['expires_at']),
            matchedAt: $row['matched_at'] === null ? null : self::time($row['matched_at']),
            matchedDemoOrderId: $row['matched_demo_order_id'] === null
                ? null
                : (int) $row['matched_demo_order_id'],
            initiatedByName: isset($row['admin_username']) && $row['admin_username'] !== null
                ? (string) $row['admin_username']
                : null,
        );
    }

    private static function stamp(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function time(mixed $value): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
