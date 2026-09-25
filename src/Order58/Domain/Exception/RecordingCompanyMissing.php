<?php

declare(strict_types=1);

namespace App\Order58\Domain\Exception;

use RuntimeException;

use function sprintf;

/**
 * A store whose recordings cannot be fetched, because there is no company code to fetch them with.
 *
 * Carries a sentence written for an administrator, because that is where it ends up: the sync page shows
 * it as a flash and queues nothing. The remedy is real and is named — the code arrives with the store
 * sync, so a store missing one has not been synced, or was synced before the field existed.
 */
final class RecordingCompanyMissing extends RuntimeException
{
    public static function forStore(int $storeSourceId): self
    {
        return new self(sprintf(
            'Recording company code is missing for store #%d. Run an Order58 store sync from Data '
                . 'Management, then try again.',
            $storeSourceId,
        ));
    }

    /**
     * Deliberately the same sentence as a missing code.
     *
     * The store id comes from a select the server rendered, so "no such store" means a stale page or a
     * tampered request rather than anything an administrator can act on differently — and a message that
     * distinguished the two would say which store ids exist.
     */
    public static function noSuchStore(int $storeSourceId): self
    {
        return self::forStore($storeSourceId);
    }
}
