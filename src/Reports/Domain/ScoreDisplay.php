<?php

declare(strict_types=1);

namespace App\Reports\Domain;

use function floor;
use function sprintf;

/**
 * Display-only formatting for the report: score wording and durations.
 *
 * The band words match the ones the chat rating control shows an agent, so an administrator reading "2/10 ·
 * Poor" sees the same phrase the agent saw. It is a deliberate local copy rather than a shared helper: this
 * module is read-only reporting, and the chat scoring UI is not refactored to serve it. If the bands ever
 * change, the chat partial is the source of truth and this follows.
 */
final class ScoreDisplay
{
    /** Top of the red band. Comments can only exist at or below this score. */
    public const LOW_MAX = 3;

    public static function band(int $score): string
    {
        return match (true) {
            $score <= 3 => 'Poor',
            $score <= 6 => 'Fair',
            $score <= 8 => 'Good',
            default => 'Excellent',
        };
    }

    /** The band slug, reused as a CSS hook so the report inherits the same colours. */
    public static function bandSlug(int $score): string
    {
        return match (true) {
            $score <= 3 => 'poor',
            $score <= 6 => 'fair',
            $score <= 8 => 'good',
            default => 'excellent',
        };
    }

    /** "8/10 · Good". */
    public static function label(int $score): string
    {
        return $score . '/10 · ' . self::band($score);
    }

    /**
     * A compact duration: "4h 25m", "12m", "45s". Zero is rendered as "0m" rather than blank, because a
     * session really can span less than a minute and an empty cell would read as missing data.
     */
    public static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);

        return $hours > 0 ? sprintf('%dh %02dm', $hours, $minutes) : $minutes . 'm';
    }

    /** Average response time, kept in seconds because answers arrive in seconds, not minutes. */
    public static function responseTime(?float $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return $seconds < 60
            ? sprintf('%.1fs', $seconds)
            : self::duration((int) $seconds);
    }
}
