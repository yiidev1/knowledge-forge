<?php

declare(strict_types=1);

namespace App\Ai\Web\Usage;

use DateTimeImmutable;
use DateTimeZone;

use function number_format;
use function strlen;
use function substr;

/**
 * Presentation helpers for the usage dashboard.
 *
 * A class rather than closures in the template so the formatting is typed, testable and stated once.
 * Everything here is pure: it turns a value into a string and touches nothing else.
 */
final class UsageFormat
{
    private const UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];

    /**
     * Binary-prefixed size, matching how OpenAI bills storage. Using decimal units here would disagree
     * with the free-allowance arithmetic by about 7%.
     */
    public static function bytes(?int $value): string
    {
        if ($value === null) {
            return '—';
        }

        $size = (float) $value;
        $unit = 'B';

        // Walked rather than indexed: a computed offset into the unit list is not provably in range,
        // and this reads the same.
        foreach (self::UNITS as $candidate) {
            $unit = $candidate;
            if ($size < 1024.0) {
                break;
            }
            $size /= 1024.0;
        }

        $decimals = $unit === 'B' || $size >= 100.0 ? 0 : 2;

        return number_format($size, $decimals) . ' ' . $unit;
    }

    public static function money(float $value): string
    {
        return '$' . number_format($value, 2);
    }

    public static function gib(float $value): string
    {
        return number_format($value, 3) . ' GiB';
    }

    /**
     * OpenAI returns Unix seconds. The zone is spelled out rather than implied, so a reader never has
     * to guess whether a timestamp is local or UTC.
     */
    public static function moment(?int $timestamp): string
    {
        if ($timestamp === null || $timestamp === 0) {
            return '—';
        }

        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i') . ' UTC';
    }

    /**
     * Enough of an identifier to recognise, not enough to fill a column. The full value stays available
     * via the copy control and the readonly field in each row's detail panel.
     */
    public static function shortId(string $id): string
    {
        return strlen($id) <= 16 ? $id : substr($id, 0, 9) . '…' . substr($id, -4);
    }

    /**
     * Maps a provider status onto one of the existing badge variants. An unrecognised status renders
     * muted rather than being hidden — a status this code has not seen is still worth showing.
     */
    public static function statusBadge(string $status): string
    {
        return match ($status) {
            'completed', 'ready' => 'success',
            'in_progress', 'provisioning', 'pending' => 'warning',
            'failed', 'cancelled', 'expired' => 'error',
            default => 'muted',
        };
    }
}
