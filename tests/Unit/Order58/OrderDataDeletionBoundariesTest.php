<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function dirname;
use function file_get_contents;
use function implode;
use function is_dir;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function strlen;
use function substr;

/**
 * Who may delete a mirrored order, and under what scope.
 *
 * These invariants are not design preferences. Mirrored orders are the only local record of what the
 * provider sent; the item rows hang off them by `ON DELETE CASCADE`; and InnoDB does not write a
 * cascaded child delete to the binary log. So an over-broad delete on the parent destroys the item rows
 * beyond even binlog recovery, and the orders themselves survive only as long as the binlog does.
 *
 * All three hold today. They are written down because what they prevent is cheap to reintroduce by
 * accident and expensive to notice: a cleanup scoped to the wrong constant looks exactly like a cleanup
 * scoped to the right one.
 */
final class OrderDataDeletionBoundariesTest extends TestCase
{
    private const ORDERS_TABLE = 'order58_orders';
    private const ITEMS_TABLE = 'order58_order_items';

    /**
     * The one production class permitted to delete item rows, and why.
     *
     * Importing an order replaces its lines rather than diffing them, so the write is a scoped delete
     * followed by an insert inside one transaction. That is the only legitimate delete in the sync path;
     * `testTheOnlyItemDeleteIsScopedToASingleOrder` pins the scope it runs under.
     */
    private const ITEM_DELETE_ALLOWED_IN = 'src/Order58/Infrastructure/DbOrder58OrderItemRepository.php';

    /**
     * Fixture stores live at or above this id; every real Order58 store is far below it.
     *
     * Measured rather than assumed: the largest real `account_id` in the mirror is six digits, while
     * every fixture constant in the suite is a nine-digit number beginning with 9. The gap is three
     * orders of magnitude, so the threshold needs no maintenance as real stores are added.
     */
    private const FIXTURE_STORE_FLOOR = 900_000_000;

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return iterable<string, string> relative path => source */
    private function phpFilesIn(string $directory): iterable
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = (string) $file->getRealPath();
                yield substr($path, strlen($this->root()) + 1) => (string) file_get_contents($path);
            }
        }
    }

    /**
     * The table names each `->delete(...)` in this source actually targets.
     *
     * The first argument is resolved through the file's own constants, because the production code
     * writes `delete(self::ITEMS, …)` rather than the table name — so matching the literal alone would
     * read a class that deletes items but merely *mentions* orders as deleting orders.
     *
     * @return list<string> one entry per delete call, each the table it resolves to, or the raw
     *                      expression when it cannot be resolved
     */
    private function deletedTables(string $source): array
    {
        // `const ITEMS = '{{%order58_order_items}}';` → ITEMS => order58_order_items
        $constants = [];
        preg_match_all("/const\s+([A-Z0-9_]+)\s*=\s*'\{\{%?([a-z0-9_]+)%?\}\}'/", $source, $sets, PREG_SET_ORDER);

        foreach ($sets as $set) {
            $constants[$set[1]] = $set[2];
        }

        $tables = [];
        preg_match_all('/->delete\(\s*([^,)]+)/', $source, $calls, PREG_SET_ORDER);

        foreach ($calls as $call) {
            $argument = trim($call[1]);

            if (preg_match("/'\{\{%?([a-z0-9_]+)%?\}\}'/", $argument, $literal) === 1) {
                $tables[] = $literal[1];
                continue;
            }

            if (preg_match('/(?:self|static)::([A-Z0-9_]+)/', $argument, $named) === 1) {
                $tables[] = $constants[$named[1]] ?? $argument;
                continue;
            }

            $tables[] = $argument;
        }

        return $tables;
    }

    /**
     * The application never deletes a mirrored order.
     *
     * Syncing is an upsert: an order that disappears from the provider is left in place rather than
     * removed, because the local row is what the Order Testing and Practice surfaces hold their history
     * against. No production path needs this, and the parent is the dangerous one — deleting it takes
     * the item rows with it by a cascade the binlog does not record.
     */
    public function testNoApplicationCodeDeletesAMirroredOrder(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->root() . '/src') as $path => $source) {
            if (in_array(self::ORDERS_TABLE, $this->deletedTables($source), true)) {
                $offenders[] = $path;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Application code must not delete mirrored orders; the sync is an upsert, and the item rows "
            . "cascade away unlogged. Offenders:\n  " . implode("\n  ", $offenders),
        );
    }

    /** Only the importer's repository replaces item rows, and nothing else in `src/` deletes them. */
    public function testOnlyTheImporterRepositoryDeletesItemRows(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->root() . '/src') as $path => $source) {
            if ($path !== self::ITEM_DELETE_ALLOWED_IN
                && in_array(self::ITEMS_TABLE, $this->deletedTables($source), true)) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, "Unexpected item delete in:\n  " . implode("\n  ", $offenders));
    }

    /**
     * The importer's delete names both halves of the identity.
     *
     * `(account_id, source_order_id)` is the order's identity — order ids are not globally unique across
     * stores — so a delete scoped by the order id alone would clear a different merchant's lines.
     */
    public function testTheOnlyItemDeleteIsScopedToASingleOrder(): void
    {
        $source = (string) file_get_contents($this->root() . '/' . self::ITEM_DELETE_ALLOWED_IN);

        self::assertSame(
            1,
            preg_match(
                "/->delete\(\s*self::ITEMS\s*,\s*\[\s*'store_account_id'\s*=>[^\]]*'source_order_id'\s*=>/s",
                $source,
            ),
            'The item replacement must be scoped by store AND order id, in that one delete call.',
        );
    }

    /**
     * Every suite that deletes order rows scopes the delete to fixture stores.
     *
     * The check is on the store constants the file declares rather than on the delete expression:
     * every cleanup in the suite scopes by a declared constant, so the constants are both the real risk
     * and the checkable thing. A constant whose name mentions ORDER is an order id, not a store id, and
     * is skipped — order ids are legitimately small, and because the identity is the pair, an order id
     * can never widen a delete that is already scoped by store.
     */
    public function testEveryTestThatDeletesOrdersScopesItToFixtureStores(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->root() . '/tests') as $path => $source) {
            $tables = $this->deletedTables($source);

            if (!in_array(self::ORDERS_TABLE, $tables, true) && !in_array(self::ITEMS_TABLE, $tables, true)) {
                continue;
            }

            preg_match_all(
                "/(?:const|define\(')\s*([A-Z0-9_]*(?:STORE|ACCOUNT)[A-Z0-9_]*)'?\s*(?:=|,)\s*(\d+)/",
                $source,
                $declared,
                PREG_SET_ORDER,
            );

            foreach ($declared as $one) {
                [, $name, $value] = $one;

                if (!str_contains($name, 'ORDER') && (int) $value < self::FIXTURE_STORE_FLOOR) {
                    $offenders[] = $path . ': ' . $name . ' = ' . $value;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'A suite that deletes order rows must scope them to a fixture store id (>= '
            . self::FIXTURE_STORE_FLOOR . "). These would delete real merchants' orders:\n  "
            . implode("\n  ", $offenders),
        );
    }
}
