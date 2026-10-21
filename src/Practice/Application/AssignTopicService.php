<?php

declare(strict_types=1);

namespace App\Practice\Application;

use App\Practice\Domain\AssignedBy;
use App\Practice\Domain\SourceOrderExistenceInterface;
use App\Practice\Domain\TopicAssignmentRepositoryInterface;
use App\Practice\Domain\TopicRepositoryInterface;
use App\Shared\Domain\Exception\ValidationException;

use function count;
use function in_array;

/**
 * Putting a topic on source orders, singly or in bulk.
 *
 * ## Three things are checked, and the order matters
 *
 * The topic must exist and still accept new work; the orders must really belong to **this** store; and
 * the selection must be bounded. The store check is not a formality — the ids arrive in a POST body,
 * and an id that belongs to another merchant must be refused rather than silently assigned, which is
 * why {@see SourceOrderExistenceInterface} takes the store id too.
 *
 * ## A bulk assignment is all or nothing
 *
 * The repository writes inside one transaction. A partial success would tell an operator "14 of 15
 * saved" with no way to find the fifteenth, and the next click would reassign the fourteen that
 * already worked.
 *
 * ## Re-assigning is an upsert, not a delete and an insert
 *
 * The unique key on `(store_source_id, source_order_id)` is the business rule — one topic per source
 * order — so two operators assigning at the same moment end with one row, whichever of them wrote last.
 */
final readonly class AssignTopicService
{
    /** A selection larger than this is a mistake or a script, not fifteen checkboxes on a page. */
    public const MAX_BULK = 200;

    public function __construct(
        private TopicRepositoryInterface $topics,
        private TopicAssignmentRepositoryInterface $assignments,
        private SourceOrderExistenceInterface $orders,
    ) {}

    public function assign(int $storeSourceId, int $sourceOrderId, int $topicId, AssignedBy $by): void
    {
        $this->assignMany($storeSourceId, [$sourceOrderId], $topicId, $by);
    }

    /**
     * @param list<int> $sourceOrderIds as posted: unordered, possibly repeated, not yet trusted
     *
     * @return int how many orders now carry the topic
     *
     * @throws ValidationException
     */
    public function assignMany(int $storeSourceId, array $sourceOrderIds, int $topicId, AssignedBy $by): int
    {
        $wanted = $this->clean($sourceOrderIds);

        if ($wanted === []) {
            throw ValidationException::forField('orders', 'Select at least one order first.');
        }

        if (count($wanted) > self::MAX_BULK) {
            throw ValidationException::forField(
                'orders',
                'Select no more than ' . self::MAX_BULK . ' orders at a time.',
            );
        }

        // "Not Selected" is not an instruction. The toolbar's empty default arrives here as 0, and it
        // has to mean "nothing was chosen" — never "take the topic off these orders". Removing a topic
        // is a single order's decision, made in that row's own dropdown, so there is no bulk path to it
        // and an empty choice changes nothing. The button is disabled in the page as well; this is the
        // half that holds when the request is hand-crafted.
        if ($topicId <= 0) {
            throw ValidationException::forField('topic_id', 'Choose a topic before assigning in bulk.');
        }

        $topic = $this->topics->find($topicId);

        if ($topic === null) {
            throw ValidationException::forField('topic_id', 'That topic no longer exists.');
        }

        if (!$topic->status->acceptsNewAssignments()) {
            throw ValidationException::forField(
                'topic_id',
                '"' . $topic->name . '" is ' . $topic->status->label()
                . ' and cannot be assigned to new orders.',
            );
        }

        /*
         * The scope rule, enforced where it actually matters.
         *
         * The dropdown on the page only offers this store's topics and the global ones, but a form is
         * not a promise: the id arrives in a request, and a request can name any topic in the
         * database. Without this, one store's curated topic could be filed against another store's
         * order by changing a number — and the listing would then show it there for good.
         *
         * Global topics pass, as they belong to every store.
         */
        if ($topic->storeSourceId !== null && $topic->storeSourceId !== $storeSourceId) {
            throw ValidationException::forField(
                'topic_id',
                '"' . $topic->name . '" belongs to another store and cannot be assigned here.',
            );
        }

        // The ids came from a form. Keep only the ones that are really this store's orders, and say so
        // plainly if any were not — silently dropping them would look like a save that worked.
        $belongs = $this->orders->existingOrderIds($storeSourceId, $wanted);

        if ($belongs === []) {
            throw ValidationException::forField(
                'orders',
                'None of the selected orders belong to this store.',
            );
        }

        if (count($belongs) !== count($wanted)) {
            throw ValidationException::forField(
                'orders',
                (count($wanted) - count($belongs)) . ' of the selected orders do not belong to this '
                . 'store. Nothing was changed.',
            );
        }

        return $this->assignments->assignMany($storeSourceId, $belongs, $topicId, $by);
    }

    public function unassign(int $storeSourceId, int $sourceOrderId, AssignedBy $by): void
    {
        $belongs = $this->orders->existingOrderIds($storeSourceId, [$sourceOrderId]);

        if ($belongs === []) {
            throw ValidationException::forField('orders', 'That order does not belong to this store.');
        }

        $this->assignments->unassign($storeSourceId, $sourceOrderId);
    }

    /**
     * De-duplicate and drop anything that is not a positive id.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function clean(array $ids): array
    {
        $seen = [];

        foreach ($ids as $id) {
            if ($id > 0 && !in_array($id, $seen, true)) {
                $seen[] = $id;
            }
        }

        return $seen;
    }
}
