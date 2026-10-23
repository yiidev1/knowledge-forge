<?php

declare(strict_types=1);

namespace App\OrderTesting\Infrastructure;

use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\Practice\PracticeResultsQuery;
use App\OrderTesting\Domain\Practice\PracticeResultsReaderInterface;
use App\OrderTesting\Domain\Practice\PractitionerSummary;
use App\Shared\Application\Time\AppTimeZone;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\Query;

use function is_array;
use function is_string;
use function str_replace;

/**
 * Practice Results, answered by the database rather than by PHP holding everything at once.
 *
 * ## The shape of the problem
 *
 * The page this serves used to build itself from two unbounded reads: every attempt on the source
 * order, and every demo order with its whole `raw_payload`. At four hundred results that is a
 * megabyte of JSON fetched to draw a table that shows none of it.
 *
 * Every query here is narrowed by `(store_source_id, source_order_id)`, which
 * `ix_order_testing_attempts_open` and `ix_order_testing_demo_orders_source` both lead with, and
 * joined to demo orders on `matched_attempt_id`, which has an index of its own. Nothing selects a
 * payload except {@see resultsFor()} and {@see unmatched()}, which are the two methods that must —
 * and both are bounded by the caller.
 *
 * ## An INNER join is the attribution rule, written as SQL
 *
 * Every query that counts a practitioner's work joins attempts to demo orders inwardly. A demo order
 * with no `matched_attempt_id` — one the importer could not attribute — therefore cannot reach a
 * practitioner's row by construction, rather than by a condition somebody has to remember. It is
 * counted separately, by {@see unmatchedCount()}, and shown in a section of its own.
 *
 * An attempt with no demo order drops out the same way: somebody pressed Practice Order and produced
 * nothing, which is not a result and must not be counted as one.
 */
final readonly class DbPracticeResultsReader implements PracticeResultsReaderInterface
{
    private const ATTEMPTS = '{{%order_testing_attempts}}';
    private const DEMOS = '{{%order_testing_demo_orders}}';
    private const ADMINS = '{{%admin_users}}';
    private const OPERATORS = '{{%order58_agents}}';

    public function __construct(
        private ConnectionInterface $connection,
        /** Only to read stored UTC instants back in the business timezone, as every page here does. */
        private AppTimeZone $appTimeZone,
    ) {}

    public function practitioners(
        int $storeSourceId,
        int $sourceOrderId,
        PracticeResultsQuery $query,
        int $limit,
        int $offset,
    ): array {
        $rows = $this->practitionerQuery($storeSourceId, $sourceOrderId, $query)
            ->select([
                'realm' => 'a.initiated_by_type',
                'actor_id' => 'a.initiated_by_id',
                'name' => new Expression('MAX(COALESCE(u.username, oa.username))'),
                'tries' => new Expression('COUNT(DISTINCT d.id)'),
                // The provider's own creation instant, which is when the practice happened. The
                // import instant is days later and is a fact about this application, not about them.
                'last_at' => new Expression('MAX(d.order_created_at)'),
            ])
            ->groupBy(['a.initiated_by_type', 'a.initiated_by_id'])
            ->orderBy($this->ordering($query->sort))
            ->limit($limit)
            ->offset($offset)
            ->all();

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $realm = InitiatorType::tryFrom((string) $row['realm']);

            if ($realm === null) {
                // A realm the enum does not know. Skipped rather than guessed: naming somebody by a
                // realm this application cannot read would be inventing an attribution.
                continue;
            }

            $name = $row['name'] ?? null;

            $out[] = new PractitionerSummary(
                realm: $realm,
                actorId: (int) $row['actor_id'],
                name: is_string($name) && $name !== '' ? $name : null,
                completedTries: (int) $row['tries'],
                lastPractisedAt: $this->instant($row['last_at'] ?? null),
            );
        }

        return $out;
    }

    public function countPractitioners(
        int $storeSourceId,
        int $sourceOrderId,
        PracticeResultsQuery $query,
    ): int {
        /*
         * Counted as distinct pairs rather than by counting the grouped rows.
         *
         * `COUNT(*)` over a GROUP BY returns one row per group, and taking its count in PHP would
         * mean fetching every group to count them — which is the unbounded read this class exists to
         * avoid. The realm is concatenated in because the pair is the identity: two realms number
         * their users separately, and counting ids alone would merge two people into one.
         */
        return (int) $this->practitionerQuery($storeSourceId, $sourceOrderId, $query)
            ->select(new Expression(
                "COUNT(DISTINCT CONCAT(a.initiated_by_type, ':', a.initiated_by_id))",
            ))
            ->scalar();
    }

    public function resultsFor(
        int $storeSourceId,
        int $sourceOrderId,
        InitiatorType $realm,
        int $actorId,
        int $limit,
        int $offset,
    ): array {
        $rows = $this->resultQuery($storeSourceId, $sourceOrderId, $realm, $actorId)
            ->select('d.*')
            // Newest first: the first row is the latest score, which is the one figure the table
            // needs and cannot count. Tie-broken by id so the order is total and a page boundary
            // cannot show the same result twice.
            ->orderBy(['d.order_created_at' => SORT_DESC, 'd.id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return $this->hydrateAll($rows);
    }

    public function countResultsFor(
        int $storeSourceId,
        int $sourceOrderId,
        InitiatorType $realm,
        int $actorId,
    ): int {
        return (int) $this->resultQuery($storeSourceId, $sourceOrderId, $realm, $actorId)
            ->select(new Expression('COUNT(DISTINCT d.id)'))
            ->scalar();
    }

    public function tryNumbersFor(
        int $storeSourceId,
        int $sourceOrderId,
        InitiatorType $realm,
        int $actorId,
    ): array {
        /*
         * Ids only, oldest first — the whole set, and nothing from it but a number.
         *
         * Over the WHOLE set deliberately: Try 3 is the third thing this person produced, and a page
         * showing their newest twenty cannot work that out from what it holds. The row is two
         * integers, so reading all of them costs a fraction of what reading one payload does.
         */
        $rows = $this->resultQuery($storeSourceId, $sourceOrderId, $realm, $actorId)
            ->select(['id' => 'd.demo_order_id'])
            ->orderBy(['d.order_created_at' => SORT_ASC, 'd.id' => SORT_ASC])
            ->all();

        $out = [];
        $n = 0;

        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[(int) $row['id']] = ++$n;
            }
        }

        return $out;
    }

    public function unmatchedCount(int $storeSourceId, int $sourceOrderId): int
    {
        return (int) (new Query($this->connection))
            ->from(['d' => self::DEMOS])
            ->where([
                'd.store_source_id' => $storeSourceId,
                'd.source_order_id' => $sourceOrderId,
                'd.matched_attempt_id' => null,
            ])
            ->count();
    }

    public function resultCount(int $storeSourceId, int $sourceOrderId): int
    {
        return (int) (new Query($this->connection))
            ->from(['d' => self::DEMOS])
            ->where(['d.store_source_id' => $storeSourceId, 'd.source_order_id' => $sourceOrderId])
            ->count();
    }

    public function unmatched(int $storeSourceId, int $sourceOrderId, int $limit, int $offset): array
    {
        $rows = (new Query($this->connection))
            ->from(['d' => self::DEMOS])
            ->where([
                'd.store_source_id' => $storeSourceId,
                'd.source_order_id' => $sourceOrderId,
                'd.matched_attempt_id' => null,
            ])
            ->orderBy(['d.order_created_at' => SORT_DESC, 'd.id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return $this->hydrateAll($rows);
    }

    /**
     * Attempts joined inwardly to the results they produced, for one source order.
     *
     * The two name joins are realm-qualified. `admin_users.id = 1` and `order58_agents.admin_id = 1`
     * are two different people in this installation, so a join on the id alone renders one under the
     * other's name — which was live behaviour here once and is what the qualification prevents.
     */
    private function practitionerQuery(
        int $storeSourceId,
        int $sourceOrderId,
        PracticeResultsQuery $query,
    ): Query {
        $admin = InitiatorType::Admin->value;
        $operation = InitiatorType::Operation->value;
        $agent = InitiatorType::Agent->value;

        $q = (new Query($this->connection))
            ->from(['a' => self::ATTEMPTS])
            ->innerJoin(['d' => self::DEMOS], 'd.matched_attempt_id = a.id')
            ->leftJoin(
                ['u' => self::ADMINS],
                "u.id = a.initiated_by_id AND a.initiated_by_type = '" . $admin . "'",
            )
            ->leftJoin(
                ['oa' => self::OPERATORS],
                "oa.admin_id = a.initiated_by_id AND a.initiated_by_type IN ('"
                    . $operation . "', '" . $agent . "')",
            )
            ->where([
                'a.store_source_id' => $storeSourceId,
                'a.source_order_id' => $sourceOrderId,
            ]);

        if ($query->search !== null) {
            /*
             * Matched against whichever name the practitioner has, as a bound parameter.
             *
             * `LIKE` with a leading wildcard cannot use an index, which is the honest cost of letting
             * somebody type half a name. It is paid against the rows this source order's attempts
             * already narrowed to — tens, not the whole table — so it is a scan of a handful of rows
             * rather than of `admin_users`.
             *
             * The user's own `%` and `_` are escaped, or searching for "a_b" would quietly match
             * "axb".
             */
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query->search) . '%';

            $q = $q->andWhere(new Expression(
                'COALESCE(u.username, oa.username) LIKE :agentSearch ESCAPE \'\\\\\'',
                [':agentSearch' => $like],
            ));
        }

        return $q;
    }

    /** One practitioner's results, by the pair that identifies them. */
    private function resultQuery(
        int $storeSourceId,
        int $sourceOrderId,
        InitiatorType $realm,
        int $actorId,
    ): Query {
        return (new Query($this->connection))
            ->from(['d' => self::DEMOS])
            ->innerJoin(['a' => self::ATTEMPTS], 'd.matched_attempt_id = a.id')
            ->where([
                'd.store_source_id' => $storeSourceId,
                'd.source_order_id' => $sourceOrderId,
                // Both halves, always. The realm is what makes the number mean somebody.
                'a.initiated_by_type' => $realm->value,
                'a.initiated_by_id' => $actorId,
            ]);
    }

    /**
     * @param array<array-key, mixed> $rows
     *
     * @return list<DemoOrder>
     */
    private function hydrateAll(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = DbDemoOrderRepository::fromRow($row);
            }
        }

        return $out;
    }

    /** A provider timestamp as an instant, or null where it carried none. */
    private function instant(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }

        return (new DateTimeImmutable('@' . (int) $value))
            ->setTimezone($this->appTimeZone->zone());
    }

    /**
     * The orderings the table offers, each made total by a tie-break on the actor.
     *
     * Without the tie-break two practitioners who last practised in the same second could swap
     * places between page one and page two, so a reader would see one of them twice and the other
     * not at all. MySQL gives no stable order for ties on its own.
     *
     * @return array<string, int>
     */
    private function ordering(string $sort): array
    {
        $tieBreak = ['a.initiated_by_type' => SORT_ASC, 'a.initiated_by_id' => SORT_ASC];

        return match ($sort) {
            'name' => ['name' => SORT_ASC] + $tieBreak,
            'tries' => ['tries' => SORT_DESC] + $tieBreak,
            default => ['last_at' => SORT_DESC] + $tieBreak,
        };
    }
}
