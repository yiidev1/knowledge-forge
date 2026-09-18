<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Tts;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Domain\Tts\TtsException;
use App\AudioToText\Domain\Tts\TtsOutputFormat;
use App\AudioToText\Domain\Tts\TtsOutputType;

use function filemtime;
use function is_dir;
use function is_file;
use function is_link;
use function is_writable;
use function mkdir;
use function preg_match;
use function realpath;
use function rename;
use function rmdir;
use function rtrim;
use function scandir;
use function sprintf;
use function str_starts_with;
use function substr;
use function unlink;

/**
 * Owns `runtime/audio-to-text/ai-audio/<jobPublicId>/` — every byte this feature ever writes to disk.
 *
 * ## Why a third tree instead of a corner of `recordings/`
 *
 * {@see \App\AudioToText\Application\QueuedAudioStorage::retain()} is a *move*. Once a job finishes,
 * `recordings/<publicId>/source.<ext>` is the **only copy** of what the administrator uploaded — the
 * temporary workspace has already been deleted. Writing synthesised chunks and merge scratch into that
 * directory would put an irreplaceable recording one prefix bug away from a `unlink()` loop, and this
 * feature deletes work files on every single run.
 *
 * There is a second, quieter reason. `removeDirectoryUnder()` over there unlinks files and does not
 * recurse, then calls `rmdir()`. A subdirectory left behind makes that `rmdir()` fail silently, at which
 * point retention deletion for that conversation becomes a permanent no-op that reports success. Staying
 * out of the tree removes any chance of introducing one.
 *
 * So: a sibling of `jobs/` and `recordings/`, under `runtime/`, outside the web root, unreachable over
 * HTTP except through the action that checks authorisation first. **This class cannot name `source.*`** —
 * {@see FILE_PATTERN} and {@see WORK_PATTERN} both require an `ai-` prefix — so no path it produces can
 * resolve to an original recording even if a caller asked it to.
 *
 * ## Why it prepares its own directories
 *
 * `QueuedAudioStorage::prepareBaseDirectories()` runs at transcription-worker startup and throws when a
 * directory cannot be made. Adding this tree to that list would give the transcription queue a brand new
 * way to refuse to start, over a feature it does not use. Zero touch is worth more than one shared line.
 *
 * ## Why nothing else sweeps this
 *
 * Neither existing sweep looks outside `jobs/` and `recordings/`, so the TTS worker calls
 * {@see sweepOrphans()} and {@see sweepWorkFiles()} itself. Without the second one, a worker killed by a
 * deploy, the OOM killer or a systemd timeout leaks its half-written chunks with nothing to collect them.
 */
final readonly class GeneratedAudioStorage
{
    private const PUBLIC_ID_PATTERN = '/^[0-9a-f]{32}$/';

    /**
     * A published file: `ai-mixed-1a2b3c4d.mp3`.
     *
     * Re-checked on the way *out* of the database as well as on the way in. A path assembled from a
     * database column is not trustworthy merely because it was trustworthy when written — the same
     * doctrine {@see \App\AudioToText\Application\QueuedAudioStorage} states for stored recordings.
     */
    private const FILE_PATTERN = '/^ai-(?:mixed|customer|agent)-[0-9a-f]{8}\.(?:mp3|wav)$/';

    /** Scratch: `ai-work-<token>.raw` and `ai-work-<token>.mp3`. Removed on both the success and failure paths. */
    private const WORK_PATTERN = '/^ai-work-[0-9a-f]{8,32}\.(?:raw|mp3|wav)$/';

    private const WORK_PREFIX = 'ai-work-';

    public function __construct(private AudioToTextSettings $settings) {}

    /**
     * The name a finished file takes.
     *
     * The digest prefix is in the name so an operator looking at the directory can tell at a glance
     * whether a file matches the row that points at it — and so a regeneration writes to a different
     * name rather than over the file it is replacing, which is what keeps the previous audio playable
     * until the moment the new one is known to be good.
     */
    public static function fileName(TtsOutputType $type, string $sourceHash, TtsOutputFormat $format): string
    {
        return sprintf('ai-%s-%s.%s', $type->slug(), substr($sourceHash, 0, 8), $format->extension());
    }

    /**
     * Create this job's directory, and hand back a scratch path inside it.
     *
     * @throws TtsException when the directory cannot be made or written to
     */
    public function beginWork(string $jobPublicId, string $token, string $extension): string
    {
        $directory = $this->directoryFor($jobPublicId);

        if ($directory === null) {
            throw TtsException::writeFailed('malformed job identifier');
        }

        $root = rtrim($this->settings->transcription->aiAudioDirectory(), '/');

        foreach ([$root, $directory] as $path) {
            if (!is_dir($path) && !@mkdir($path, 0o700, true) && !is_dir($path)) {
                throw TtsException::writeFailed(sprintf('could not create "%s"', $path));
            }
        }

        if (!is_writable($directory)) {
            throw TtsException::writeFailed(sprintf('"%s" is not writable', $directory));
        }

        $name = self::WORK_PREFIX . $token . '.' . $extension;

        if (preg_match(self::WORK_PATTERN, $name) !== 1) {
            throw TtsException::writeFailed('refusing to create a work file with an unexpected name');
        }

        return $directory . '/' . $name;
    }

    /**
     * Move a finished work file to its published name.
     *
     * `rename()` within one directory is atomic, so a reader either sees the old file or the complete new
     * one and never a half-written one. The row is repointed only after this returns, which is why a
     * crash here leaks a file rather than orphaning a database row — the cheaper of the two failures, and
     * the leak is collected by {@see sweepWorkFiles()}.
     *
     * @throws TtsException when the move fails
     */
    public function publish(string $jobPublicId, string $workPath, string $fileName): void
    {
        $target = $this->pathForName($jobPublicId, $fileName);

        if ($target === null) {
            throw TtsException::writeFailed('refusing to publish under an unexpected filename');
        }

        if (!is_file($workPath)) {
            throw TtsException::writeFailed('the generated file disappeared before it could be published');
        }

        if (!@rename($workPath, $target)) {
            throw TtsException::writeFailed(sprintf('could not move the generated file into "%s"', $fileName));
        }
    }

    /**
     * Absolute path of a published file, or null when it is not there.
     *
     * The only way the web tier ever learns a path. Returns null rather than throwing for anything
     * unexpected — a malformed id, a name that is not ours, a file that has gone — so the caller has one
     * branch to write and every failure collapses to the same 404 rather than to a message that tells an
     * anonymous reader which of those things went wrong.
     */
    public function pathFor(string $jobPublicId, ?string $fileName): ?string
    {
        if ($fileName === null) {
            return null;
        }

        $path = $this->pathForName($jobPublicId, $fileName);

        return $path !== null && is_file($path) ? $path : null;
    }

    /**
     * Delete one published file — the superseded one, after a replacement has been published.
     *
     * Best effort by design. A failure here must never fail a generation that has already succeeded and
     * already been paid for: the file is left for {@see sweepOrphans()} and the caller logs it.
     *
     * Only a name matching {@see FILE_PATTERN} is ever passed to `unlink()`, so this cannot be talked
     * into deleting a recording, and it is confined to the `ai-audio/` tree in any case.
     */
    public function remove(string $jobPublicId, ?string $fileName): bool
    {
        $path = $this->pathFor($jobPublicId, $fileName);

        return $path !== null && @unlink($path);
    }

    /** Remove every scratch file for one job. Called on the success path and the failure path alike. */
    public function removeWorkFiles(string $jobPublicId): void
    {
        $directory = $this->resolvedDirectory($jobPublicId);

        if ($directory === null) {
            return;
        }

        foreach ($this->entriesIn($directory) as $entry) {
            if (preg_match(self::WORK_PATTERN, $entry) === 1) {
                @unlink($directory . '/' . $entry);
            }
        }
    }

    /**
     * Remove directories whose job no longer exists.
     *
     * Mirrors `sweepOrphanedRecordings()`: a job row is what makes generated audio meaningful, so its
     * absence is the only condition under which anything is deleted. Age-gated too, so a job mid-flight
     * is never swept.
     *
     * @param callable(string): bool $jobStillExists
     *
     * @return list<string> the ids removed
     */
    public function sweepOrphans(callable $jobStillExists, int $olderThanSeconds, int $now): array
    {
        $root = realpath($this->settings->transcription->aiAudioDirectory());

        if ($root === false) {
            return [];
        }

        $removed = [];

        foreach ($this->entriesIn($root) as $entry) {
            if (preg_match(self::PUBLIC_ID_PATTERN, $entry) !== 1) {
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

            if ($jobStillExists($entry)) {
                continue;
            }

            $this->removeDirectory($resolved, $root);

            // A directory this process cannot remove is left alone and not reported, rather than counted
            // as a deletion that did not happen.
            if (!is_dir($resolved)) {
                $removed[] = $entry;
            }
        }

        return $removed;
    }

    /**
     * Collect scratch left behind by a worker that was killed.
     *
     * The gap nothing else covers. A generation interrupted by a deploy, the OOM killer or a systemd
     * timeout leaves chunk files in a directory whose job row is perfectly healthy, so
     * {@see sweepOrphans()} will never touch it and the files would accumulate forever.
     *
     * `$activeJobPublicIds` are the jobs whose renditions are GENERATING right now; their work files
     * belong to a run in progress and must survive.
     *
     * @param list<string> $activeJobPublicIds
     *
     * @return int how many files were removed
     */
    public function sweepWorkFiles(array $activeJobPublicIds, int $olderThanSeconds, int $now): int
    {
        $root = realpath($this->settings->transcription->aiAudioDirectory());

        if ($root === false) {
            return 0;
        }

        $active = [];
        foreach ($activeJobPublicIds as $id) {
            $active[$id] = true;
        }

        $removed = 0;

        foreach ($this->entriesIn($root) as $entry) {
            if (preg_match(self::PUBLIC_ID_PATTERN, $entry) !== 1 || isset($active[$entry])) {
                continue;
            }

            $directory = $this->resolvedDirectory($entry);

            if ($directory === null) {
                continue;
            }

            foreach ($this->entriesIn($directory) as $file) {
                if (preg_match(self::WORK_PATTERN, $file) !== 1) {
                    continue;
                }

                $path = $directory . '/' . $file;
                $modified = @filemtime($path);

                if ($modified === false || ($now - $modified) < $olderThanSeconds) {
                    continue;
                }

                if (@unlink($path)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * The absolute path a published name would have, without checking the file exists.
     *
     * Both halves are validated — the job id and the filename — and the result is confined to this job's
     * directory by construction, because it is assembled from two values that have each been proved to
     * contain nothing but the characters their pattern allows. There is no path component here that a
     * caller could steer.
     */
    private function pathForName(string $jobPublicId, string $fileName): ?string
    {
        if (preg_match(self::FILE_PATTERN, $fileName) !== 1) {
            return null;
        }

        $directory = $this->directoryFor($jobPublicId);

        return $directory === null ? null : $directory . '/' . $fileName;
    }

    private function directoryFor(string $jobPublicId): ?string
    {
        if (preg_match(self::PUBLIC_ID_PATTERN, $jobPublicId) !== 1) {
            return null;
        }

        return rtrim($this->settings->transcription->aiAudioDirectory(), '/') . '/' . $jobPublicId;
    }

    /** The directory as it exists on disk, refusing anything that resolves outside the tree. */
    private function resolvedDirectory(string $jobPublicId): ?string
    {
        $directory = $this->directoryFor($jobPublicId);

        if ($directory === null || !is_dir($directory)) {
            return null;
        }

        $root = realpath($this->settings->transcription->aiAudioDirectory());
        $resolved = realpath($directory);

        if ($root === false || $resolved === false || !str_starts_with($resolved, $root . '/')) {
            return null;
        }

        return (string) $resolved;
    }

    /**
     * Delete one job's directory, refusing to act outside the tree it was told to stay in.
     *
     * Flat by construction, exactly like the job and recording directories: this tree holds files and
     * never subdirectories, so nothing recurses and nothing can be talked into recursing.
     */
    private function removeDirectory(string $directory, string $root): void
    {
        $resolved = realpath($directory);

        if ($resolved === false || !str_starts_with($resolved, $root . '/')) {
            return;
        }

        $resolved = (string) $resolved;

        foreach ($this->entriesIn($resolved) as $entry) {
            $path = $resolved . '/' . $entry;

            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }

        @rmdir($resolved);
    }

    /**
     * @return list<string> directory entries, `.` and `..` excluded
     */
    private function entriesIn(string $directory): array
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            return [];
        }

        $names = [];
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $names[] = $entry;
            }
        }

        return $names;
    }
}
