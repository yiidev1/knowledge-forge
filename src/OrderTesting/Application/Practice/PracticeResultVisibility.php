<?php

declare(strict_types=1);

namespace App\OrderTesting\Application\Practice;

use App\OrderTesting\Domain\DemoOrder;
use App\OrderTesting\Domain\TestActorInterface;
use App\OrderTesting\Domain\TestAttemptRepositoryInterface;

use function array_values;

/**
 * Whether a demo order is the reader's own work.
 *
 * ## Why this is a service and not three copies of an `if`
 *
 * Three pages reach a demo order by its id in the path — the comparison, the printed receipt and the
 * flat list. On the practice surface each of them must show the reader only their own results, and a
 * check written three times is a check that will be right in two places. So the question is asked
 * once, here, and the three pages differ only in *whether* they ask it: the management surfaces do
 * not, because seeing everybody's work is what management is.
 *
 * ## Unmatched is not "mine"
 *
 * A demo order with no `matched_attempt_id` is one the importer could not attribute to anybody. It is
 * not withheld because it is secret — it is withheld because claiming it would be an invention, which
 * is the same reason the importer left it alone. The management surfaces still list it, under
 * Unmatched, where it belongs.
 *
 * ## The realm travels with the id, always
 *
 * `admin_users.id = 171` and an Order58 `admin_id = 171` are different people, and this application
 * has already been bitten once by a join that forgot it. Both halves are compared, never the number
 * on its own.
 */
final readonly class PracticeResultVisibility
{
    public function __construct(
        private TestAttemptRepositoryInterface $attempts,
        /** The session the middleware verified — never anything the request body offered. */
        private TestActorInterface $actor,
    ) {}

    /** Whether the signed-in reader produced this demo order themselves. */
    public function belongsToReader(DemoOrder $demo): bool
    {
        if ($demo->matchedAttemptId === null) {
            return false;
        }

        $attempt = $this->attempts->byIds([$demo->matchedAttemptId])[$demo->matchedAttemptId] ?? null;

        return $attempt !== null
            && $attempt->initiatedByType === $this->actor->initiatorType()
            && $attempt->initiatedById === $this->actor->initiatorId();
    }

    /**
     * The reader's own demo orders, out of a page's worth.
     *
     * One query for every attribution rather than one per row, which is how the list already resolved
     * the names it prints.
     *
     * @param list<DemoOrder> $demos
     *
     * @return list<DemoOrder>
     */
    public function onlyTheReaders(array $demos): array
    {
        $ids = [];

        foreach ($demos as $demo) {
            if ($demo->matchedAttemptId !== null) {
                $ids[$demo->matchedAttemptId] = $demo->matchedAttemptId;
            }
        }

        if ($ids === []) {
            return [];
        }

        $attempts = $this->attempts->byIds(array_values($ids));
        $realm = $this->actor->initiatorType();
        $id = $this->actor->initiatorId();
        $mine = [];

        foreach ($attempts as $attempt) {
            if ($attempt->initiatedByType === $realm && $attempt->initiatedById === $id) {
                $mine[$attempt->id] = true;
            }
        }

        $out = [];

        foreach ($demos as $demo) {
            if ($demo->matchedAttemptId !== null && isset($mine[$demo->matchedAttemptId])) {
                $out[] = $demo;
            }
        }

        return $out;
    }
}
