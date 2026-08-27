<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use DateTimeInterface;

use function basename;
use function pathinfo;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_contains;
use function substr;
use function trim;

use const PATHINFO_FILENAME;

/**
 * Builds the download filename, and can re-check one.
 *
 * The name is rebuilt from the stored original at download time rather than persisted, so there is
 * never a stored string that could have been crafted at upload time and replayed into a response
 * header months later.
 *
 * The character class is the point: after folding, a name cannot contain a quote, a semicolon, a
 * newline or a path separator, so `Content-Disposition` can never be made to carry header syntax of
 * its own.
 */
final readonly class TranscriptFilename
{
    private const FALLBACK_STEM = 'audio';
    private const MAX_STEM_LENGTH = 60;
    private const SAFE_PATTERN = '/^[A-Za-z0-9._-]{1,120}\.txt$/';

    /**
     * @param string $part 'transcript', 'agent' or 'customer' — appears in the name so three downloads
     *                     of one job do not overwrite each other in the browser's downloads folder
     */
    public static function for(?string $clientFilename, DateTimeInterface $moment, string $part = 'transcript'): string
    {
        $suffix = match ($part) {
            'agent' => 'agent',
            'customer' => 'customer',
            default => 'transcript',
        };

        // UTC, so two administrators in different timezones downloading the same job get the same name.
        return sprintf('%s-%s-%s.txt', self::stem($clientFilename), $suffix, $moment->format('Ymd-His'));
    }

    public static function isSafe(string $filename): bool
    {
        return preg_match(self::SAFE_PATTERN, $filename) === 1 && !str_contains($filename, '..');
    }

    private static function stem(?string $clientFilename): string
    {
        if ($clientFilename === null || $clientFilename === '') {
            return self::FALLBACK_STEM;
        }

        // basename() first: a client may have sent "../../etc/passwd.wav", and pathinfo() alone would
        // happily hand back "passwd" from a string we should not be walking at all.
        $stem = pathinfo(basename($clientFilename), PATHINFO_FILENAME);
        $stem = trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $stem), '-');

        if ($stem === '') {
            return self::FALLBACK_STEM;
        }

        return substr($stem, 0, self::MAX_STEM_LENGTH);
    }
}
