<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

use function preg_match;

/**
 * The one shape a demo-order file may have: `{SOURCE_ORDER_ID}-{DEMO_ORDER_ID}.json`.
 *
 * The listener names them that way because the pair is the only place the link between a source order
 * and the order a trainee produced from it survives — Order58's own document knows its own id and the
 * composite `uid`, but the filename is what the existing production script already commits to.
 *
 * ## It is a whitelist, not a sanitiser
 *
 * Nothing here repairs a name. A value that is not two runs of digits joined by one hyphen and ending in
 * `.json` is refused outright, which is what makes `..`, a path separator, a nested directory, a
 * dotfile, a symlink target with a crafted name and a `.json.php` double extension all impossible to
 * express — rather than merely stripped and hoped about. The importer only ever joins an accepted name
 * to a directory it resolved itself, so the joined path cannot leave that directory.
 *
 * ## The ids are bounded
 *
 * Nineteen digits is the width of a signed 64-bit integer. A longer run is refused rather than
 * truncated, because a truncated id is a different order.
 */
final readonly class DemoOrderFileName
{
    private function __construct(
        public string $basename,
        public int $sourceOrderId,
        public int $demoOrderId,
    ) {}

    /** Null when the name is not one this importer will open. */
    public static function parse(string $basename): ?self
    {
        if (preg_match('/^([1-9][0-9]{0,18})-([1-9][0-9]{0,18})\.json$/', $basename, $m) !== 1) {
            return null;
        }

        return new self($basename, (int) $m[1], (int) $m[2]);
    }
}
