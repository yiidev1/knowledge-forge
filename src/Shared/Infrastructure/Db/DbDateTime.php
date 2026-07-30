<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Db;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Converts between the application's UTC `DateTimeImmutable` and the `DATETIME` strings MySQL stores.
 *
 * Centralised so every repository formats and parses timestamps identically. The database session runs
 * in UTC (pinned in {@see DbConnectionFactory}), so no zone conversion happens here — only formatting.
 */
final class DbDateTime
{
    public const FORMAT = 'Y-m-d H:i:s';

    public static function format(DateTimeInterface $dateTime): string
    {
        return $dateTime->format(self::FORMAT);
    }

    public static function parse(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    public static function parseNullable(?string $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : self::parse($value);
    }
}
