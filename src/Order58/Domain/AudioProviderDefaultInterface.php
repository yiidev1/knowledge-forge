<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * Which transcription engine a new audio upload defaults to, for the store-audio page to display.
 *
 * The same arrangement as {@see StoreAudioCountsInterface}, for the same reason: this module may not
 * name the Audio-to-Text module — `ModuleIsolationTest` matches that namespace literally, in comments
 * too, and fails the build — so the store-audio page asks its own port rather than reaching for that
 * module's repository. The implementation reads one audio table directly. A table name is not a
 * namespace, and one small query is cheaper than a dependency between modules.
 *
 * ## Why this is read-only
 *
 * The page **shows** the setting and posts a form to change it, but the change is handled by the route
 * that owns the setting, addressed by route name. Writing it from here would put the validation rules
 * for a provider list in two modules, and the second copy would be the one that went stale.
 *
 * ## Plain strings, deliberately
 *
 * The provider is a `string`, not the audio module's enum, because naming that enum is exactly what is
 * forbidden. The values are stable storage identifiers with a database CHECK constraint behind them, so
 * a string here is not a loose contract — it is the same contract the column enforces.
 */
interface AudioProviderDefaultInterface
{
    /**
     * The stored default, as a storage value such as `WHISPER`.
     *
     * Always one of {@see choices()}. An unreadable or unrecognised row reads as the first choice
     * rather than as an empty selection, so the page always renders something coherent.
     */
    public function current(): string;

    /**
     * Every selectable provider, as `storage value => label`, in the order they should be offered.
     *
     * Returned by the port rather than written into the template so the list has **one** place in this
     * module rather than one per view. It is a deliberate mirror of the audio module's own list — the
     * isolation rule that forbids naming that module forbids importing its enum too, so this is the
     * same kind of duplication as the table name above, and it is checked the same way: a value that
     * drifts is rejected by the `CHECK` constraint on the column, and a test asserts the two lists
     * agree.
     *
     * @return array<string, string>
     */
    public function choices(): array;
}
