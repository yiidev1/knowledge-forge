<?php

declare(strict_types=1);

namespace App\Order58\Application\Mapper;

use App\Order58\Application\Formatter\StoreProfileFields;
use App\Order58\Contract\Dto\Order58Account;
use App\Order58\Domain\StoreMirror;

use function is_scalar;
use function is_string;
use function preg_match;
use function trim;

/**
 * Maps an Order58 account into a {@see StoreMirror}, building a curated, credential-free snapshot that is
 * the single deterministic source for the store-profile document. Accounts have no reliable source-updated
 * timestamp, so `sourceUpdatedAt` is always null.
 *
 * It also owns {@see normaliseHost()}, the single definition of what counts as a usable hostname. The
 * sync handler calls it directly for the one case that never builds a mirror — a record whose
 * `_sync_hash` is unchanged but whose host moved — so both paths agree by construction rather than by
 * two developers writing the same validation twice.
 */
final class StoreMapper
{
    public function toMirror(Order58Account $account): StoreMirror
    {
        $fields = [];
        foreach (StoreProfileFields::FIELDS as $key => $label) {
            $value = $account->raw[$key] ?? null;
            if (is_scalar($value) && $value !== '' && $value !== 0) {
                $fields[$label] = $value;
            }
        }

        $snapshot = [
            'id' => $account->id,
            'name' => $account->name,
            'active' => $account->active,
            'fields' => $fields,
        ];

        return new StoreMirror(
            id: null,
            sourceId: $account->id,
            name: $account->name,
            company: $account->company,
            active: $account->active,
            syncHash: $account->syncHash,
            sourceUpdatedAt: null,
            snapshot: $snapshot,
            host: $this->hostFor($account),
        );
    }

    /** The account's usable hostname, or null when it gave nothing this application will store. */
    public function hostFor(Order58Account $account): ?string
    {
        return self::normaliseHost($account->raw['host'] ?? null);
    }

    /**
     * What this application will accept as a hostname, and nothing more.
     *
     * Returns the trimmed value or **null**, and null always means the same thing: the source said
     * nothing usable. Absent, JSON `null`, `''`, whitespace, a number, an array and a malformed string
     * all collapse to it, because the write path's response to every one of them is identical — keep
     * whatever is already stored.
     *
     * The pattern accepts a bare hostname and refuses a scheme, a path, a port, an `@` or whitespace.
     * That is the shape the source actually sends: all 235 mirrored hosts are bare names between 16 and
     * 38 characters, none carrying `://`, `:` or `/`. Nothing is rewritten to fit — a value that is not
     * already a hostname is declined rather than repaired, because guessing what somebody meant by
     * `https://shop.example.com/` is how a column fills with values no two readers parse alike.
     *
     * Kept byte-identical to the `REGEXP` in `M261002120000AddOrder58StoreHost`, so the backfill and the
     * sync cannot disagree about what a host is.
     */
    public static function normaliseHost(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $host = trim($value);

        return preg_match('/^[A-Za-z0-9]([A-Za-z0-9._-]{0,253}[A-Za-z0-9])?$/', $host) === 1 ? $host : null;
    }
}
