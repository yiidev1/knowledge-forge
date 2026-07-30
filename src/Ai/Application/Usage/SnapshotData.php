<?php

declare(strict_types=1);

namespace App\Ai\Application\Usage;

use function is_array;
use function is_numeric;
use function is_string;

/**
 * Type-checked readers for rehydrating a snapshot from decoded JSON.
 *
 * The cache file is data this code wrote, but it is still a file on disk that could be truncated, hand
 * edited or left over from an older shape. Reading it through declared return types means a malformed
 * value degrades to a sensible default at the boundary instead of becoming a `mixed` that surfaces as a
 * type error somewhere deep in the template.
 *
 * @internal
 */
final class SnapshotData
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function float(array $data, string $key): float
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    public static function bool(array $data, string $key): bool
    {
        return ($data[$key] ?? null) === true;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, string>
     */
    public static function stringMap(array $data, string $key): array
    {
        $map = [];
        foreach (self::array($data, $key) as $k => $v) {
            if (is_string($v)) {
                $map[(string) $k] = $v;
            }
        }

        return $map;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<array<array-key, mixed>>
     */
    public static function rows(array $data, string $key): array
    {
        $rows = [];
        foreach (self::array($data, $key) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
