<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

/**
 * Stores and reads the imported demo orders.
 *
 * Every write is keyed on the triple `(store_source_id, source_order_id, demo_order_id)`, which is what
 * makes rescanning the same directory free of consequence. Nothing here updates one demo order because
 * another arrived: a later order is a new row, and the history is the feature.
 */
interface DemoOrderRepositoryInterface
{
    /**
     * Insert, or refresh the row the triple already names.
     *
     * Idempotent by design: a second import of an unchanged document compares equal on `content_hash`
     * and writes nothing at all, so pressing Sync demo orders twice in a row writes nothing the
     * second time.
     *
     * @param int|null $matchAttemptId credited only on INSERT. An order that already exists keeps the
     *                                 attribution it was given; re-running the importer must never move
     *                                 somebody else's work onto a newer attempt.
     *
     * @return DemoOrderUpsert what happened, so the command can report counts honestly
     */
    public function upsert(DemoOrder $order, ?int $matchAttemptId): DemoOrderUpsert;

    /**
     * How many demo orders each of these source orders has, for one store.
     *
     * Plural because the store page asks for a whole page of rows at once; a method answering for one
     * order would be the N+1 this exists to avoid.
     *
     * @param list<int> $sourceOrderIds
     *
     * @return array<int, int> source order id => count, including zeros for ids with none
     */
    public function countsFor(int $storeSourceId, array $sourceOrderIds): array;

    /**
     * Every demo order for one source order of one store, newest first.
     *
     * **Scoped by both ids on purpose.** The store comes from the URL and the order from the URL, and
     * the query is bounded by both — so a source order id belonging to another merchant cannot be made
     * to render here by typing it.
     *
     * @return list<DemoOrder>
     */
    public function forSourceOrder(int $storeSourceId, int $sourceOrderId): array;

    /**
     * One demo order, addressed by all three parts of its identity.
     *
     * The comparison page takes store, source order and demo order from the URL and passes all three
     * here. Looking one up by its demo id alone would let a URL reach another merchant's demo order,
     * so the narrower signature is the security boundary rather than a convenience.
     */
    public function findOne(int $storeSourceId, int $sourceOrderId, int $demoOrderId): ?DemoOrder;
}
