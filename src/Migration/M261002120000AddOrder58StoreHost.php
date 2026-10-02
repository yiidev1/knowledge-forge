<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Promotes each store's `host` out of the snapshot blob and into a column of its own.
 *
 * ## The value is already here
 *
 * `host` has never been missing. The Order58 accounts response carries it, the parser keeps the whole
 * record in `Order58Account::$raw`, and {@see \App\Order58\Application\Formatter\StoreProfileFields} has
 * always selected it into `snapshot_json.fields.Host` for the store-profile document. Every one of the
 * 235 mirrored stores has one today.
 *
 * What it has never been is *queryable*. A JSON path is the wrong place to read a per-store hostname from
 * when something needs to join or filter on it, so this moves it to a column and leaves the snapshot
 * exactly as it is — the document formatter still reads its own copy and is untouched.
 *
 * ## The backfill needs no API call
 *
 * Because the value is already stored, existing rows are populated from `snapshot_json` in this
 * migration. That matters more than it looks: store sync only rewrites a row when the provider's
 * `_sync_hash` changes, so a column left for "the next sync" could stay NULL indefinitely for a store
 * nobody edits. Backfilling here means the column is complete the moment it exists.
 *
 * The same validation the application applies on the write path is applied here, as a MySQL `REGEXP`:
 * a bare hostname, no scheme, no path, no whitespace. A snapshot value that does not look like a host
 * is left NULL rather than copied — an unparseable value in a typed column is worse than an absent one.
 *
 * ## Why no index and no uniqueness
 *
 * All 235 hosts are distinct today, but that is an observation, not a contract Order58 publishes. A
 * UNIQUE key would turn a provider-side duplicate into a sync failure, which is a severe response to a
 * field nothing yet joins on. An index can be added later by whatever first needs to look a store up
 * this way.
 */
final class M261002120000AddOrder58StoreHost implements RevertibleMigrationInterface
{
    private const STORES = 'order58_stores';

    /**
     * A bare hostname: starts and ends alphanumeric, dots/hyphens/underscores between.
     *
     * Rejects anything carrying a scheme, a path, a port, an `@` or whitespace. Kept byte-identical to
     * the pattern in {@see \App\Order58\Application\Mapper\StoreMapper::normaliseHost()} so the backfill
     * and the sync can never disagree about what a host is.
     */
    private const HOST_PATTERN = '^[A-Za-z0-9]([A-Za-z0-9._-]{0,253}[A-Za-z0-9])?$';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE `' . self::STORES . '`
                ADD COLUMN `host` VARCHAR(255) NULL AFTER `company`',
        );

        // Populate from what is already stored. TRIM matches the application's normalisation; the REGEXP
        // is what stops a malformed snapshot value becoming a malformed column value.
        $b->execute(
            'UPDATE `' . self::STORES . '`
                SET `host` = TRIM(JSON_UNQUOTE(JSON_EXTRACT(`snapshot_json`, \'$.fields.Host\')))
              WHERE JSON_CONTAINS_PATH(`snapshot_json`, \'one\', \'$.fields.Host\')
                AND JSON_UNQUOTE(JSON_EXTRACT(`snapshot_json`, \'$.fields.Host\')) IS NOT NULL
                AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(`snapshot_json`, \'$.fields.Host\')))
                    REGEXP \'' . self::HOST_PATTERN . '\'',
        );
    }

    /**
     * Refuse rather than destroy, the house rule.
     *
     * The column is not merely a copy of the snapshot. A host-only change arrives with an unchanged
     * `_sync_hash`, and the sync updates the column **without** rewriting `snapshot_json` — deliberately,
     * so a hostname change costs no document regeneration. Any such row holds the only current host in
     * the database, and dropping the column would silently roll it back to a stale snapshot value.
     *
     * So this stops when it finds one and says how many, instead of discarding them. A database where no
     * host has diverged yet reverts cleanly.
     */
    public function down(MigrationBuilder $b): void
    {
        $diverged = (int) $b->getDb()->createCommand(
            'SELECT COUNT(*) FROM `' . self::STORES . '`
              WHERE `host` IS NOT NULL
                AND `host` <> COALESCE(
                    TRIM(JSON_UNQUOTE(JSON_EXTRACT(`snapshot_json`, \'$.fields.Host\'))), \'\')',
        )->queryScalar();

        if ($diverged > 0) {
            throw new RuntimeException(
                $diverged . ' store(s) hold a host that is newer than their snapshot, so dropping the '
                . 'column would lose the only current value. Reconcile those rows first if the column '
                . 'really must go.',
            );
        }

        $b->execute('ALTER TABLE `' . self::STORES . '` DROP COLUMN `host`');
    }
}
