<?php

declare(strict_types=1);

namespace App\Shared\Audio;

/**
 * Recordings a store has asked for that have not all arrived yet.
 *
 * ## Why this seam exists, and which way it points
 *
 * The audio module's store page renders what it has: a row per order, built from stored conversations.
 * A recording that has been requested but not downloaded has no conversation — there is no audio, no
 * duration, no transcript — so until now that page could not show it at all. Press Download, and for a
 * minute or two the store page looked exactly as it had before, which reads as nothing having happened.
 *
 * The facts needed to say otherwise live in the importing module's own tables, and the two modules are
 * not allowed to name each other. {@see AudioIngestionPortInterface} already crosses this seam in the
 * other direction — "here is a file, take it" — and this is its read-only counterpart: "what is on its
 * way?" The audio module depends on this interface; the module that does the importing implements it.
 *
 * ## What an implementation must not do
 *
 * Return anything that is already here. A channel that has been downloaded has a conversation, and the
 * store page draws it from that; reporting it here as well would show the same recording twice, once as
 * a player and once as a status. The contract is **outstanding work only**.
 */
interface PendingRecordingPortInterface
{
    /**
     * Calls for this store with at least one channel still outstanding, newest request first.
     *
     * Each entry carries every channel of its call, including the ones already downloaded, because the
     * reader needs to see "mixed is here, the caller side is still coming" as one thing rather than as
     * a row and a half. It is the *call* that is outstanding, not each channel separately.
     *
     * An empty list is the normal answer and must be cheap: every render of the store page asks, and
     * most of the time nothing is being downloaded.
     *
     * @return list<RecordingAcquisition>
     */
    public function activeForStore(int $storeSourceId): array;
}
