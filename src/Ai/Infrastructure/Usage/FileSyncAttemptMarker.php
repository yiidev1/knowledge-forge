<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure\Usage;

use App\Ai\Application\Usage\SnapshotData;
use App\Ai\Application\Usage\SyncAttemptMarkerInterface;
use DateTimeImmutable;
use DateTimeZone;

use function chmod;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;

use const JSON_THROW_ON_ERROR;

/**
 * The "when did we last try" marker, in its own tiny file.
 *
 * Separate from the snapshot for one reason: the snapshot only advances when a sync SUCCEEDS. Throttling
 * on it would mean a sync that keeps failing is never throttled at all, which is exactly when repeated
 * attempts are most useless and most likely.
 *
 * The file holds one ISO-8601 timestamp and nothing else — no keys, no counts, no identifiers — so it is
 * safe to sit in a directory the web user can read. A missing or unreadable marker reads as "no recent
 * attempt", which fails open: the worst case is one extra allowed sync, not a blocked page.
 */
final readonly class FileSyncAttemptMarker implements SyncAttemptMarkerInterface
{
    private const FILE_MODE = 0664;

    public function __construct(
        private string $path,
    ) {}

    public function lastAttemptAt(): ?DateTimeImmutable
    {
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = @json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $recorded = SnapshotData::nullableString($decoded, 'last_attempt_at');
        if ($recorded === null) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $recorded);

        return $parsed === false ? null : $parsed->setTimezone(new DateTimeZone('UTC'));
    }

    public function markAttempt(DateTimeImmutable $at): void
    {
        $this->ensureDirectory(dirname($this->path));

        $json = json_encode(
            ['last_attempt_at' => $at->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::ATOM)],
            JSON_THROW_ON_ERROR,
        );

        // Best effort by design. Failing to record an attempt must not abort the sync the operator
        // asked for; the cost of a lost marker is one extra permitted attempt.
        @file_put_contents($this->path, $json);
        @chmod($this->path, self::FILE_MODE);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
    }
}
