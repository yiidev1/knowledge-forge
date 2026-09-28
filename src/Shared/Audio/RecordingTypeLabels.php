<?php

declare(strict_types=1);

namespace App\Shared\Audio;

/**
 * What a recording type is called on screen. The only place those words are written down.
 *
 * ## Why here rather than on the enum
 *
 * The enum lives in the transcription module, and `ModuleIsolationTest` forbids **any** other directory
 * under `src/` from naming that module — a rule so literal that this sentence may not spell the
 * namespace out. Order58's store cards show the same three counts and need the same three words. So the
 * mapping cannot live in either module and has to be here, keyed by the storage value, exactly as
 * {@see AudioIngestionPortInterface} passes `MIXED` across the same seam as a plain string.
 *
 * The enum's own `label()` delegates to this, so there is still one method to call from inside that
 * module and one table of words in the application. Changing what a client calls these is one edit.
 *
 * ## Display only. The stored values do not move
 *
 * `CALLER` and `CALLEE` remain `CALLER` and `CALLEE` in the database, in the API, in the provider's
 * channel vocabulary and in every enum case. This turns them into English for an administrator and does
 * nothing else — no query, no grouping rule and no branch anywhere reads what comes out of here.
 *
 * ## Customer and Agent are also SourceRole's words, and that is accepted
 *
 * A legacy SEPARATE upload has a `source_role` of CUSTOMER or AGENT, and those already display as
 * "Customer" and "Agent". A CALLER recording now displays as "Customer" too, so the two read alike on
 * screen while meaning different things: `source_role` says who works for the restaurant, `recording_type`
 * says who dialled. That collision is deliberate and was signed off — the client's operators think in
 * Customer and Agent, and separate pairs are historical.
 *
 * What it must never become is a collision in the code. CALLER is not `SourceRole::Customer`, nothing
 * maps one onto the other, and the three vocabularies this application keeps apart —
 * `RecordingType`, `RecordingChannel`, `SourceRole` — are exactly as separate as they were.
 */
final class RecordingTypeLabels
{
    /**
     * Keyed by the stored value, because that is what crosses this seam.
     *
     * MIXED reads "Mix / Common" — the words the store page's own column has always used — so the table
     * header and the label inside a dialog cannot disagree about the same recording.
     */
    private const LABELS = [
        'MIXED' => 'Mix / Common',
        'CALLER' => 'Customer',
        'CALLEE' => 'Agent',
    ];

    /**
     * The display name for a stored recording type, or null when it is not one of the three.
     *
     * Null rather than the raw value: a caller that has something else has a bug or a legacy row, and
     * printing `CALLEE_2` on a page is a worse answer than printing nothing and letting the caller say
     * what it wants to show instead.
     */
    public static function forStorageValue(?string $value): ?string
    {
        return $value === null ? null : (self::LABELS[$value] ?? null);
    }

    /**
     * Every label, in the order the store page's columns run.
     *
     * For a caller rendering all three at once — a set of table headers, a breakdown card — so the order
     * is decided here with the words rather than restated at each site.
     *
     * @return array<string, string> stored value => display label
     */
    public static function all(): array
    {
        return self::LABELS;
    }
}
