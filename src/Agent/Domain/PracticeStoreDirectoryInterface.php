<?php

declare(strict_types=1);

namespace App\Agent\Domain;

/**
 * The stores an agent may practise on.
 *
 * One predicate — "holds at least one recording that still has its audio and names a source order of
 * this same store" — and this port is the listing half of it. The object-level half lives in
 * {@see \App\Shared\Web\Actor\PracticeStoreScope}, which owns the SQL both halves are built from, so a
 * store that appears in this list cannot be refused by the gate or the other way round.
 */
interface PracticeStoreDirectoryInterface
{
    /**
     * Every qualifying store, in name order.
     *
     * The whole list rather than a page: the picker counts letters across all of it before showing
     * one, exactly as the agent store-chat picker does, and the mirrored store table is small.
     *
     * @return list<PracticeStore>
     */
    public function findAvailable(): array;
}
