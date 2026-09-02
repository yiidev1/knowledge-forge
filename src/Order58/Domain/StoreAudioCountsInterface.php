<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * How many conversions each store has.
 *
 * This module may not name the Audio-to-Text module — `ModuleIsolationTest` matches that namespace
 * literally and fails the build — so the store-audio picker asks its own port rather than reaching for
 * that module's repository. The implementation reads the audio tables directly, which is the same
 * shape, in the other direction, as Audio-to-Text reading the store mirror in `DbAudioStoreLookup`:
 * a table name is not a namespace, and one small query is cheaper than a dependency between modules.
 *
 * **Conversions, not jobs.** A separate Customer + Agent upload is one conversion and two jobs, and
 * this is the number an administrator counts, so it must never be the second one.
 */
interface StoreAudioCountsInterface
{
    /**
     * Conversion counts for the stores on one page, keyed by store source id.
     *
     * One query for the whole page rather than one per card: a page of 36 stores would otherwise be 36
     * round trips to print 36 numbers. Stores with nothing uploaded are absent from the result rather
     * than present as zero — the caller defaults, and an absent key and a zero mean the same thing.
     *
     * @param list<int> $sourceIds
     *
     * @return array<int, int>
     */
    public function countsFor(array $sourceIds): array;

    /**
     * Every store that has at least one conversion.
     *
     * Used to restrict the directory query itself, so the "Uploaded audio" filter narrows the rows,
     * the total and the letter counts together — a filter that only hid cards would leave a pager
     * promising pages that render empty.
     *
     * @return list<int>
     */
    public function storesWithAudio(): array;
}
