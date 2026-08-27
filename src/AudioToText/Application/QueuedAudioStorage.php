<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\AudioTranscriptionException;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

use function copy;
use function rename;
use function unlink;
use function rmdir;
use function file_exists;
use function filemtime;
use function in_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_writable;
use function mkdir;
use function preg_match;
use function realpath;
use function rtrim;
use function scandir;
use function sprintf;
use function str_starts_with;
use function strtolower;

/**
 * Owns the private on-disk layout for queued recordings, and every path check that goes with it.
 *
 *     <tempDir>/worker.lock            the single-worker guarantee
 *     <tempDir>/jobs/<publicId>/       one directory per job, unguessable
 *     <tempDir>/jobs/<publicId>/source.<ext>
 *
 * `worker.lock` sits *beside* `jobs/`, never inside it. That is deliberate: the orphan sweep deletes
 * directories under `jobs/`, and a lock file within its reach could be removed by the very process it
 * is supposed to be constraining.
 *
 * Nothing here is under `public/`, so a queued recording is never reachable over HTTP even for the few
 * seconds it exists. Directories are 0750 and 0700 — never 0777.
 */
final readonly class QueuedAudioStorage
{
    private const PUBLIC_ID_PATTERN = '/^[0-9a-f]{32}$/';
    private const STORED_NAME_PATTERN = '/^source\.[a-z0-9]{1,10}$/';
    private const SOURCE_STEM = 'source';

    public function __construct(
        private AudioToTextSettings $settings,
    ) {}

    /**
     * Creates `<tempDir>` and `<tempDir>/jobs` if absent. Called by both the upload path and the worker,
     * because either may be the first to run on a fresh deployment.
     */
    public function prepareBaseDirectories(): void
    {
        $directories = [
            $this->settings->transcription->temporaryDirectory,
            $this->settings->transcription->jobsDirectory(),
            $this->settings->transcription->recordingsDirectory(),
        ];

        foreach ($directories as $directory) {
            if ($directory === '') {
                throw AudioTranscriptionException::temporaryDirectoryNotWritable(
                    '(unset)',
                    'AUDIO_TRANSCRIPTION_TEMP_DIR resolved to an empty path',
                );
            }

            if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
                throw AudioTranscriptionException::temporaryDirectoryNotWritable(
                    $directory,
                    'it could not be created',
                );
            }

            if (!is_writable($directory)) {
                throw AudioTranscriptionException::temporaryDirectoryNotWritable(
                    $directory,
                    'it is not writable by the current user',
                );
            }
        }
    }

    /**
     * Writes the upload into its own job directory under a server-generated name.
     *
     * The client's filename never becomes a path. Only its extension survives, lower-cased and length
     * limited, and the stem is always the literal "source" — so a hostile name has nowhere to go.
     *
     * @return string the bare stored filename, e.g. `source.wav`
     */
    public function store(string $publicId, UploadedFileInterface $file, string $extension): string
    {
        $this->assertPublicId($publicId);
        $this->prepareBaseDirectories();

        $directory = $this->directoryFor($publicId);
        if (!@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw AudioTranscriptionException::temporaryDirectoryNotWritable(
                $directory,
                'the per-job directory could not be created',
            );
        }

        $safeExtension = strtolower($extension);
        if (preg_match('/^[a-z0-9]{1,10}$/', $safeExtension) !== 1) {
            $safeExtension = 'bin';
        }

        $storedName = self::SOURCE_STEM . '.' . $safeExtension;

        try {
            $file->moveTo($directory . '/' . $storedName);
        } catch (Throwable $e) {
            $this->remove($publicId);

            throw AudioTranscriptionException::uploadUnreadable(
                sprintf('the upload could not be written: %s', $e->getMessage()),
            );
        }

        return $storedName;
    }

    /**
     * Absolute path of a job's stored recording, or null when it is gone.
     *
     * The stored name is re-validated on the way out even though this class wrote it. The value has been
     * to the database and back, and a path assembled from a database column is exactly the kind of thing
     * that should not be trusted just because it was trustworthy when it was written.
     */
    public function pathFor(string $publicId, ?string $storedName): ?string
    {
        if ($storedName === null || preg_match(self::STORED_NAME_PATTERN, $storedName) !== 1) {
            return null;
        }

        try {
            $this->assertPublicId($publicId);
        } catch (AudioTranscriptionException) {
            return null;
        }

        $path = $this->directoryFor($publicId) . '/' . $storedName;

        return is_file($path) ? $path : null;
    }

    /**
     * Deletes a job's directory and everything in it. Safe to call twice, and safe to call for a job
     * that never wrote anything — cleanup runs in `finally` and must never itself become the failure.
     */
    public function remove(string $publicId): void
    {
        try {
            $this->assertPublicId($publicId);
        } catch (AudioTranscriptionException) {
            return;
        }

        $this->removeDirectory($this->directoryFor($publicId));
    }

    /**
     * Removes job directories that no live job owns any more.
     *
     * Every guard here is load-bearing. Only the dedicated jobs directory is ever scanned; the resolved
     * candidate must be a direct child of the resolved jobs directory, so a symlink pointing elsewhere
     * resolves outside and is rejected; the name must be 32 hex characters, so nothing that was not
     * created by this feature matches; and only directories older than the stale window are touched, so
     * an upload in flight is never swept out from under the request that is writing it.
     *
     * @param list<string> $activePublicIds
     *
     * @return list<string> the ids removed, for the worker's console output
     */
    public function sweepOrphans(array $activePublicIds, int $olderThanSeconds, int $now): array
    {
        $jobsDirectory = realpath($this->settings->transcription->jobsDirectory());
        if ($jobsDirectory === false) {
            return [];
        }

        $entries = @scandir($jobsDirectory);
        if ($entries === false) {
            return [];
        }

        $removed = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (preg_match(self::PUBLIC_ID_PATTERN, $entry) !== 1) {
                continue;
            }

            if (in_array($entry, $activePublicIds, true)) {
                continue;
            }

            $candidate = $jobsDirectory . '/' . $entry;

            // Never follow a symlink: resolving through one could land the recursive delete anywhere.
            if (is_link($candidate)) {
                continue;
            }

            $resolved = realpath($candidate);
            if ($resolved === false || $resolved !== $jobsDirectory . '/' . $entry || !is_dir($resolved)) {
                continue;
            }

            $modified = @filemtime($resolved);
            if ($modified === false || ($now - $modified) < $olderThanSeconds) {
                continue;
            }

            $this->removeDirectory($resolved);
            $removed[] = $entry;
        }

        return $removed;
    }

    /**
     * Moves a successful job's recording out of the temporary workspace and into permanent storage.
     *
     * A move, not a copy: the recording exists in exactly one place at a time, so there is never a
     * window where the sweeper could collect a file the database already considers retained.
     *
     * `rename()` first — it is atomic within a filesystem — falling back to copy+unlink for the case
     * where `runtime/` spans a mount boundary.
     *
     * @return string|null the bare retained filename, or null when there was nothing to retain
     */
    public function retain(string $publicId, ?string $storedName): ?string
    {
        $source = $this->pathFor($publicId, $storedName);

        if ($source === null || $storedName === null) {
            return null;
        }

        $directory = $this->recordingDirectoryFor($publicId);

        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw AudioTranscriptionException::temporaryDirectoryNotWritable(
                $directory,
                'the retained-recording directory could not be created',
            );
        }

        $destination = $directory . '/' . $storedName;

        if (@rename($source, $destination)) {
            return $storedName;
        }

        if (@copy($source, $destination)) {
            @unlink($source);

            return $storedName;
        }

        throw AudioTranscriptionException::temporaryDirectoryNotWritable(
            $destination,
            'the recording could not be moved into permanent storage',
        );
    }

    /**
     * Absolute path of a retained recording, or null when it is gone.
     *
     * Re-validated exactly like {@see pathFor()}: the value has been to the database and back, and a
     * path assembled from a database column is not trustworthy merely because it was when written.
     */
    public function retainedPathFor(string $publicId, ?string $retainedName): ?string
    {
        if ($retainedName === null || preg_match(self::STORED_NAME_PATTERN, $retainedName) !== 1) {
            return null;
        }

        try {
            $this->assertPublicId($publicId);
        } catch (AudioTranscriptionException) {
            return null;
        }

        $path = $this->recordingDirectoryFor($publicId) . '/' . $retainedName;

        return is_file($path) ? $path : null;
    }

    /**
     * Deletes a retained recording.
     *
     * Called only when the owning job row is being purged under a configured retention window — never
     * by the orphan sweep, and never while retention is indefinite.
     */
    public function removeRetained(string $publicId): void
    {
        try {
            $this->assertPublicId($publicId);
        } catch (AudioTranscriptionException) {
            return;
        }

        $this->removeDirectoryUnder(
            $this->recordingDirectoryFor($publicId),
            $this->settings->transcription->recordingsDirectory(),
        );
    }

    /**
     * Removes retained recordings whose job row no longer exists.
     *
     * A recording is retained *because* a row references it, so the absence of that row is what makes
     * one an orphan — and is the only condition under which this deletes anything. It therefore cannot
     * touch a recording that is still retained, whatever the retention setting says, because such a
     * recording has a row by definition.
     *
     * Deliberately narrow: `$stillExists` is asked per candidate rather than loading every known id, so
     * this stays cheap even with an unbounded number of retained conversations, and only directories
     * older than the stale window are considered so a job mid-flight is never swept.
     *
     * @param callable(string): bool $stillExists
     *
     * @return list<string> the ids removed
     */
    public function sweepOrphanedRecordings(callable $stillExists, int $olderThanSeconds, int $now): array
    {
        $root = realpath($this->settings->transcription->recordingsDirectory());
        if ($root === false) {
            return [];
        }

        $entries = @scandir($root);
        if ($entries === false) {
            return [];
        }

        $removed = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || preg_match(self::PUBLIC_ID_PATTERN, $entry) !== 1) {
                continue;
            }

            $candidate = $root . '/' . $entry;

            if (is_link($candidate)) {
                continue;
            }

            $resolved = realpath($candidate);
            if ($resolved === false || $resolved !== $root . '/' . $entry || !is_dir($resolved)) {
                continue;
            }

            $modified = @filemtime($resolved);
            if ($modified === false || ($now - $modified) < $olderThanSeconds) {
                continue;
            }

            if ($stillExists($entry)) {
                continue;
            }

            $this->removeDirectoryUnder($resolved, $this->settings->transcription->recordingsDirectory());

            // A directory owned by another user cannot be removed by this process; leave it and say so
            // rather than reporting a deletion that did not happen.
            if (!is_dir($resolved)) {
                $removed[] = $entry;
            }
        }

        return $removed;
    }

    private function recordingDirectoryFor(string $publicId): string
    {
        return rtrim($this->settings->transcription->recordingsDirectory(), '/') . '/' . $publicId;
    }

    private function directoryFor(string $publicId): string
    {
        return rtrim($this->settings->transcription->jobsDirectory(), '/') . '/' . $publicId;
    }

    private function assertPublicId(string $publicId): void
    {
        if (preg_match(self::PUBLIC_ID_PATTERN, $publicId) !== 1) {
            throw AudioTranscriptionException::uploadUnreadable('malformed job identifier');
        }
    }

    /**
     * Deletes one job directory. Flat by construction — a job directory holds files, never
     * subdirectories — so this does not recurse, and cannot be talked into recursing.
     */
    private function removeDirectory(string $directory): void
    {
        $this->removeDirectoryUnder($directory, $this->settings->transcription->jobsDirectory());
    }

    /**
     * Deletes one directory, refusing to act outside the tree it was told to stay in.
     *
     * The containment check is the point: whatever the caller passed, the resolved path must be a
     * descendant of the resolved root, so a symlink or a crafted id cannot redirect the delete.
     */
    private function removeDirectoryUnder(string $directory, string $root): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $rootDirectory = realpath($root);
        $resolved = realpath($directory);
        if ($rootDirectory === false || $resolved === false) {
            return;
        }

        if (!str_starts_with($resolved, $rootDirectory . '/')) {
            return;
        }

        $entries = @scandir($resolved);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $resolved . '/' . $entry;
                if (is_file($path) || is_link($path)) {
                    @unlink($path);
                }
            }
        }

        if (file_exists($resolved)) {
            @rmdir($resolved);
        }
    }
}
