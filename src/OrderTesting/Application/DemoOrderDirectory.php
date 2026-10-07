<?php

declare(strict_types=1);

namespace App\OrderTesting\Application;

use App\OrderTesting\Domain\DemoOrderFileName;

use function explode;
use function in_array;
use function is_dir;
use function is_file;
use function is_readable;
use function realpath;
use function scandir;
use function sort;
use function str_starts_with;

/**
 * The one directory this feature reads, and the guarantee that it is not the live one.
 *
 * ## The live-order directory is unreachable by construction
 *
 * Production keeps real orders in `/data/orders/mix_orders` and demo orders in
 * `/data/orders/demo_mix_orders`. Those two names differ by a prefix, which is exactly the kind of
 * difference a typo erases — and the consequence of that typo is this application importing live
 * customer orders and displaying them as training material.
 *
 * So the configured path is resolved and **every segment of it is checked**: a directory that is, or
 * sits anywhere beneath, a segment named `mix_orders` is refused outright and nothing is read. The
 * segment comparison is what makes this work — `demo_mix_orders` is a different segment, not a longer
 * one, so a `str_contains` would have refused the right directory and a `str_starts_with` would have
 * allowed the wrong one.
 *
 * `is_test` is checked again on every document; see {@see DemoOrderDocument}. Two independent defences,
 * because one of them is configuration and configuration is edited by people in a hurry.
 *
 * ## Nothing here writes
 *
 * The importer opens files and never creates, renames, moves, truncates or deletes one. The listener
 * that writes them is a separate program on a separate schedule and is not this application's to
 * coordinate with — so the only safe assumption is that a file may appear or vanish mid-scan, which is
 * why every read tolerates its own file being gone.
 */
final readonly class DemoOrderDirectory
{
    /** A path segment that means live orders. Never a prefix test; see the class docblock. */
    private const LIVE_SEGMENT = 'mix_orders';

    public function __construct(private string $configuredPath) {}

    /**
     * The resolved, readable, non-live directory — or a reason it cannot be used.
     *
     * Returns a reason rather than throwing: a missing directory is the normal state of a development
     * machine that has never run a demo order, and the command says so and exits cleanly rather than
     * reporting a failure somebody then goes looking for.
     */
    public function resolve(): DemoOrderDirectoryStatus
    {
        if ($this->configuredPath === '') {
            return DemoOrderDirectoryStatus::unusable(
                'ORDER_TESTING_DEMO_ORDERS_DIR is not set, so there is nowhere to import from.',
            );
        }

        // The configured value is checked FIRST, before the directory is resolved or even has to exist.
        //
        // The obvious order — resolve, then check — is wrong in the one case that matters. `realpath()`
        // returns false for a path that does not exist yet, so a deployment pointed at the live order
        // directory on a machine where it is not mounted would be told "does not exist", which reads as
        // "create it". The refusal has to be about what was configured, not about what is on disk.
        if (self::namesTheLiveDirectory($this->configuredPath)) {
            return DemoOrderDirectoryStatus::unusable(
                'refusing to read ' . $this->configuredPath . ': it is, or is inside, the LIVE order '
                . 'directory. This feature imports demo orders only.',
            );
        }

        $resolved = realpath($this->configuredPath);

        if ($resolved === false || !is_dir($resolved)) {
            return DemoOrderDirectoryStatus::unusable(
                'the configured demo-order directory does not exist: ' . $this->configuredPath,
            );
        }

        // And again on the RESOLVED path, which is what defeats a symlink: the configured value can be
        // an innocent name that points at the live directory, and only the resolved path shows that.
        if (self::namesTheLiveDirectory($resolved)) {
            return DemoOrderDirectoryStatus::unusable(
                'refusing to read ' . $resolved . ': it is, or is inside, the LIVE order directory. '
                . 'This feature imports demo orders only.',
            );
        }

        if (!is_readable($resolved)) {
            return DemoOrderDirectoryStatus::unusable(
                'the configured demo-order directory is not readable: ' . $resolved,
            );
        }

        return DemoOrderDirectoryStatus::usable($resolved);
    }

    /**
     * Whether a path is, or sits beneath, a segment named `mix_orders`.
     *
     * Segment by segment, and that is the whole trick: `str_contains` would refuse `demo_mix_orders`
     * — the RIGHT directory — and `str_starts_with` would let `/data/orders/mix_orders_old` through.
     * Trailing slashes and duplicated separators produce empty segments, which match nothing.
     */
    private static function namesTheLiveDirectory(string $path): bool
    {
        return in_array(self::LIVE_SEGMENT, explode('/', $path), true);
    }

    /**
     * The demo-order files in a resolved directory, oldest name first.
     *
     * Only names matching {@see DemoOrderFileName} are returned; everything else in the directory — a
     * temporary file the listener is still writing, a `.gz` an operator left, a subdirectory — is
     * skipped silently, because this directory is not ours and its other contents are not our business.
     *
     * Not recursive. The listener writes flat, and descending would turn an unrelated mounted tree into
     * work this does.
     *
     * @return list<DemoOrderFileName>
     */
    public function filesIn(string $resolvedDirectory): array
    {
        $entries = @scandir($resolvedDirectory);

        if ($entries === false) {
            return [];
        }

        sort($entries);

        $files = [];

        foreach ($entries as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            $name = DemoOrderFileName::parse($entry);

            if ($name === null) {
                continue;
            }

            // The joined path is a directory this class resolved plus a name proved to be digits, a
            // hyphen and `.json`. It cannot leave the directory.
            if (!is_file($resolvedDirectory . '/' . $entry)) {
                continue;
            }

            $files[] = $name;
        }

        return $files;
    }
}
