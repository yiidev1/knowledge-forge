<?php

declare(strict_types=1);

namespace App\Shared\Audio;

use function array_keys;
use function array_map;
use function is_numeric;
use function in_array;
use function number_format;
use function rtrim;
use function round;

/**
 * How long the system voice waits between two spoken messages, and the only list of those values.
 *
 * ## Why one definition rather than four
 *
 * The same ten values are needed by the dropdown, by the validator that accepts a tracking event, by
 * the reporting that prints what was selected, and by the tests for all three. Written out in each
 * place, adding an eleventh would be four edits and the one that was forgotten would be the one that
 * silently refused a value the page had already offered. So the list is here, the browser is *handed*
 * it by the server, and nothing downstream of this class knows a number of its own.
 *
 * ## Milliseconds inside, seconds on screen
 *
 * Stored, validated and compared as whole milliseconds — `0.3` is not representable in binary floating
 * point, and a gap that arrives as `0.30000000000000004` must not fail a comparison it should pass.
 * Seconds exist only in the label a person reads.
 *
 * ## This is not the TTS gap, and not the speed
 *
 * `DEEPGRAM_TTS_GAP_MS` is the silence baked into a *generated audio file* by the provider; it belongs
 * to a rendition that already exists and is not changed by anything here. The reading speed is a rate
 * applied to each utterance. This is the pause between two utterances in the browser's own voice.
 * Three separate things, deliberately not shared.
 */
final readonly class PlaybackGapOptions
{
    /**
     * What the system voice has always waited where no one can choose: the legacy pause, in
     * milliseconds, used by the surfaces that do not offer this control.
     *
     * Named here so the two places that mean "the old behaviour" agree about what it was, and so
     * changing one does not quietly leave the other behind.
     */
    public const LEGACY_MS = 1500;

    /** Where the dropdown starts. A full second: the pause a reader notices without waiting on it. */
    public const DEFAULT_MS = 1000;

    /**
     * The allowed gaps, in milliseconds, shortest first. The only list.
     *
     * Five training intervals rather than the ten tenths this began with. The short ones were measured
     * and were working exactly as specified — but at two tenths of a second the pause is a minority of
     * the silence a listener actually hears, because the speech engine's own startup latency is of the
     * same order, so the setting could not do the job it was there for. These are spaced far enough
     * apart to be told apart by ear.
     *
     * Values that were offered before and are not here any more remain perfectly valid in the records.
     * Nothing re-validates a stored gap: {@see label()} formats whatever it is given, and the history
     * on the Manage Orders page still reads the sessions that used them.
     */
    private const ALLOWED_MS = [500, 1000, 2000, 3000, 4000];

    /**
     * The widest a stored gap may ever be, for the database's own bound.
     *
     * A range rather than the list above, on purpose: a CHECK that named the offered values would have
     * to be migrated every time the list changes — which is a schema change to edit a dropdown, and
     * worse, it would make the rows that used an earlier list illegal. The list is enforced in PHP,
     * where it lives; the column only refuses nonsense, and every gap ever offered stays storable.
     */
    public const MIN_STORABLE_MS = 100;
    public const MAX_STORABLE_MS = 5000;

    /** @return list<int> */
    public static function allowed(): array
    {
        return self::ALLOWED_MS;
    }

    public static function isAllowed(int $milliseconds): bool
    {
        return in_array($milliseconds, self::ALLOWED_MS, true);
    }

    /**
     * `500` becomes `0.5 s` and `2000` becomes `2 s` — what the dropdown and the report both print.
     *
     * Trailing zeros are trimmed rather than padded to two places, so a whole number of seconds reads
     * as one. It must stay willing to format a value the dropdown no longer offers: the records hold
     * gaps from every list this control has ever had, and a report that could not print one would be
     * hiding what somebody actually did.
     */
    public static function label(int $milliseconds): string
    {
        $seconds = $milliseconds / 1000;

        return rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.') . ' s';
    }

    /**
     * Whatever arrived, as a whole number of milliseconds, or null when it is not an offered gap.
     *
     * Accepts the two shapes a browser can send — `300` and `"0.3"` — because the form value is
     * seconds and a retry or a hand-written request may carry either. Anything else is null, and null
     * is refused by the caller rather than quietly turned into a default: a gap nobody chose must not
     * be recorded as one they did.
     */
    public static function normalize(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        // Under 100 it can only be seconds — the longest offered gap is 4 — and at or above it,
        // milliseconds, the shortest offered being 500. The two ranges cannot meet, so the same
        // function reads `4` and `4000` as the same gap and neither as the other.
        $milliseconds = (int) round($number < 100 ? $number * 1000 : $number);

        return self::isAllowed($milliseconds) ? $milliseconds : null;
    }

    /**
     * The options as the page hands them to the browser: value in milliseconds, label in seconds.
     *
     * @return list<array{ms: int, label: string}>
     */
    public static function forBrowser(): array
    {
        return array_map(
            static fn(int $ms): array => ['ms' => $ms, 'label' => self::label($ms)],
            self::ALLOWED_MS,
        );
    }

    /**
     * The same options for a server-rendered `<select>`, keyed by value.
     *
     * @return array<int, string>
     */
    public static function forSelect(): array
    {
        $out = [];

        foreach (self::ALLOWED_MS as $ms) {
            $out[$ms] = self::label($ms);
        }

        return $out;
    }

    /** @return list<int> the keys of {@see forSelect()}, for a caller that wants only the values */
    public static function values(): array
    {
        return array_keys(self::forSelect());
    }
}
