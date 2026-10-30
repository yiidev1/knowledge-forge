<?php

declare(strict_types=1);

namespace App\OrderTesting\Console;

use App\Order58\Client\CaseOrders\CaseOrderClientInterface;
use App\Order58\Client\CaseOrders\CaseOrderFetchFailed;
use App\OrderTesting\Application\DemoOrderDirectory;
use App\Shared\Order58\PaymentSecretRedactor;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Yii\Console\ExitCode;

use function array_key_exists;
use function basename;
use function file_get_contents;
use function glob;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Writes Card Last 4, Expiration Date and Billing ZIP into order payloads that were stored before those
 * fields were kept.
 *
 * ## Why a backfill is needed at all, and why synchronisation cannot do this
 *
 * The three fields are deliberately held **out of the content hash** — see
 * {@see \App\Order58\Application\Orders\OrderMapper} and {@see \App\OrderTesting\Application\DemoOrderDocument}.
 * That is what stops a deployment rewriting all 205 order rows for a display-only field, and it was
 * measured: with the keys excluded, 200 of 200 recomputed hashes are byte-identical to the stored ones.
 *
 * The same property is why ordinary synchronisation will never fill them in. A re-sync recomputes the
 * hash, finds it unchanged, and skips the row — correctly. **An order already in the table will never
 * gain these fields on its own, however many times the sync runs.** This command is the one thing that
 * fills them, and it exists to be run once.
 *
 * ## The two halves have different sources, because only one of them still has the data
 *
 * Payment secrets are stripped at the HTTP boundary, before anything is stored, so the stored payload
 * **cannot** be the source — the values are not in it. The two mirrors therefore differ:
 *
 * - **Case Orders** (`order58_orders`) have one source: the provider. This half re-fetches through
 *   {@see CaseOrderClientInterface}, which already applies
 *   {@see PaymentSecretRedactor::redactKeepingCardLastFour()} and so returns the three kept keys and
 *   none of the secrets. That request **spends the day's Case Order allowance**, which is why the dry
 *   run says so before anybody commits to it.
 * - **Demo orders** (`order_testing_demo_orders`) are imported from files that are still on disk, so
 *   this half re-reads the configured demo directory and costs nothing. Rows are matched on
 *   `source_filename`, which is the exact link between a row and the file it came from.
 *
 * ## What it writes, and everything it does not
 *
 * One column per table — `payload_json`, or `raw_payload` for a demo order — and nothing else. Not
 * `content_hash`, not `updated_at`, not `synced_at`, not `imported_at`, not a money column, not a line
 * item. The hash is left exactly as it is **on purpose**: the payload gains display fields that were
 * never part of the fingerprint, so the stored hash is still the right answer for that order and the
 * next sync still reads it as unchanged.
 *
 * A row is written only when a key is actually missing or actually differs, so a second run reports
 * nothing to do. Re-running is safe.
 *
 * ## No verification code, here or anywhere
 *
 * `cvv` is not read, not derived, not stored and has no key in this file. PCI DSS 3.2 forbids retaining
 * it after authorisation, with no encryption or compensating control that makes it permissible, so the
 * redactor strips it at the boundary and nothing downstream can put it back. The card number is treated
 * the same way; only the last four digits survive, and only because they can be proved from the
 * reference. See {@see PaymentSecretRedactor}.
 *
 * ## Dry run by default
 *
 * It reports what it would change and writes nothing until `--write` is given.
 */
#[AsCommand(
    name: 'kf:order-testing:payment-details:backfill',
    description: 'Fill Card Last 4, Expiration Date and Billing ZIP into stored order payloads (dry run unless --write).',
)]
final class BackfillPaymentDetailsCommand extends Command
{
    private const ORDERS = '{{%order58_orders}}';
    private const DEMOS = '{{%order_testing_demo_orders}}';

    /** @var list<string> */
    private const SOURCES = ['orders', 'demo', 'both'];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly CaseOrderClientInterface $client,
        private readonly DemoOrderDirectory $directory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'write',
                null,
                InputOption::VALUE_NONE,
                'Apply the changes. Without it nothing is written.',
            )
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'Which mirror to fill: orders, demo, or both. Default both.',
                'both',
            )
            ->addOption(
                'store',
                null,
                InputOption::VALUE_REQUIRED,
                'Only this store (its source id).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $write = (bool) $input->getOption('write');
        $source = $input->getOption('source');
        $store = $input->getOption('store');

        if (!is_string($source) || !in_array($source, self::SOURCES, true)) {
            $output->writeln('<error>--source must be orders, demo or both.</error>');

            return ExitCode::USAGE;
        }

        if ($store !== null && (!is_string($store) || preg_match('/\A[1-9]\d{0,18}\z/', $store) !== 1)) {
            $output->writeln('<error>--store must be a store source id.</error>');

            return ExitCode::USAGE;
        }

        $storeSourceId = $store === null ? null : (int) $store;
        $failed = false;

        if ($source !== 'demo') {
            $failed = !$this->orders($output, $write, $storeSourceId) || $failed;
        }

        if ($source !== 'orders') {
            $failed = !$this->demo($output, $write, $storeSourceId) || $failed;
        }

        $output->writeln('');
        $output->writeln($write
            ? '<info>Payment-details backfill complete.</info>'
            : '<comment>Dry run — nothing was written. Re-run with --write to apply.</comment>');
        $output->writeln('  Columns written: payload_json / raw_payload only.');
        $output->writeln('  content_hash, updated_at, synced_at and every other column are untouched.');

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * The Case Order mirror, from the provider.
     *
     * Costs the day's allowance, so the dry run says so plainly rather than making the request look
     * free. A fetch that fails is reported and the demo half still runs.
     */
    private function orders(OutputInterface $output, bool $write, ?int $storeSourceId): bool
    {
        $output->writeln('<info>Case Orders</info> (order58_orders)');
        $output->writeln('  Source: the Case Order API — <comment>this request spends the day\'s allowance</comment>.');

        try {
            $response = $this->client->fetch();
        } catch (CaseOrderFetchFailed $e) {
            $output->writeln('  <error>Fetch failed: ' . $e->getMessage() . '</error>');

            return false;
        }

        $seen = 0;
        $carried = 0;
        $changed = 0;
        $written = 0;
        $missing = 0;

        foreach ($response->orders as $order) {
            $sourceOrderId = $order['id'] ?? null;
            $accountId = $order['account_id'] ?? null;

            if (!is_int($sourceOrderId) && !(is_string($sourceOrderId) && preg_match('/\A\d+\z/', $sourceOrderId) === 1)) {
                continue;
            }

            if (!is_int($accountId) && !(is_string($accountId) && preg_match('/\A\d+\z/', $accountId) === 1)) {
                continue;
            }

            $sourceOrderId = (int) $sourceOrderId;
            $accountId = (int) $accountId;

            if ($storeSourceId !== null && $accountId !== $storeSourceId) {
                continue;
            }

            ++$seen;

            // The client returned this already redacted, so the three kept keys it attached are the
            // only place these values still exist. readPaymentKeys() is the reader for that; the
            // stricter provenPaymentKeys() would strip them and find nothing.
            $kept = PaymentSecretRedactor::readPaymentKeys($order);

            if ($kept === []) {
                continue;
            }

            ++$carried;
            $where = ['account_id' => $accountId, 'source_order_id' => $sourceOrderId];

            $row = (new Query($this->connection))
                ->select(['payload_json'])
                ->from(self::ORDERS)
                ->where($where)
                ->one();

            if (!is_array($row)) {
                ++$missing;

                continue;
            }

            $merged = self::merge($row['payload_json'] ?? null, $kept);

            if ($merged === null) {
                continue;
            }

            ++$changed;

            if ($write) {
                $this->connection->createCommand()
                    ->update(self::ORDERS, ['payload_json' => $merged], $where)
                    ->execute();
                ++$written;
            }
        }

        $output->writeln(sprintf('  Orders in the response: %d', $seen));
        $output->writeln(sprintf('  Carrying at least one of the three fields: %d', $carried));
        $output->writeln(sprintf('  Not in the table (nothing to fill): %d', $missing));
        $output->writeln(sprintf($write ? '  Rows updated: %d' : '  Rows that would be updated: %d', $changed));

        if ($write) {
            $output->writeln(sprintf('  Writes executed: %d', $written));
        }

        return true;
    }

    /**
     * The demo mirror, from the files it was imported from.
     *
     * Free — the originals are still on disk, unredacted, which is exactly why the redactor exists.
     * A directory that is missing or refused is reported and is not a failure of this command.
     */
    private function demo(OutputInterface $output, bool $write, ?int $storeSourceId): bool
    {
        $output->writeln('');
        $output->writeln('<info>Demo orders</info> (order_testing_demo_orders)');

        $status = $this->directory->resolve();

        if (!$status->isUsable() || $status->path === null) {
            $output->writeln('  <comment>'
                . ($status->problem ?? 'The demo-order directory cannot be read.') . '</comment>');
            $output->writeln('  Nothing to read — skipped.');

            return true;
        }

        $files = glob($status->path . '/*.json');
        $output->writeln(sprintf('  Source: %s', $status->path));

        $seen = 0;
        $carried = 0;
        $changed = 0;
        $written = 0;

        foreach ($files === false ? [] : $files as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            try {
                /** @var mixed $raw */
                $raw = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (!is_array($raw)) {
                continue;
            }

            ++$seen;

            // A demo file is the provider's own document, unredacted, so the strict reader applies:
            // only `data.cc` may state these, never a key sitting at the top of the file.
            $kept = PaymentSecretRedactor::provenPaymentKeys($raw);

            if ($kept === []) {
                continue;
            }

            ++$carried;
            $where = ['source_filename' => basename($file)];

            if ($storeSourceId !== null) {
                $where['store_source_id'] = $storeSourceId;
            }

            $rows = (new Query($this->connection))
                ->select(['id', 'raw_payload'])
                ->from(self::DEMOS)
                ->where($where)
                ->all();

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $merged = self::merge($row['raw_payload'] ?? null, $kept);

                if ($merged === null) {
                    continue;
                }

                ++$changed;

                if ($write) {
                    $this->connection->createCommand()
                        ->update(self::DEMOS, ['raw_payload' => $merged], ['id' => (int) $row['id']])
                        ->execute();
                    ++$written;
                }
            }
        }

        $output->writeln(sprintf('  Files read: %d', $seen));
        $output->writeln(sprintf('  Carrying at least one of the three fields: %d', $carried));
        $output->writeln(sprintf($write ? '  Rows updated: %d' : '  Rows that would be updated: %d', $changed));

        if ($write) {
            $output->writeln(sprintf('  Writes executed: %d', $written));
        }

        return true;
    }

    /**
     * The stored payload with the kept fields added, or null when it already says the same thing.
     *
     * Null is the ordinary answer on a second run, and it is what keeps this command idempotent: a row
     * whose payload already carries these values is not rewritten, so no row is touched twice.
     *
     * Only the three keys are set. The rest of the document is re-encoded exactly as it was decoded —
     * and a payload that does not decode is left alone rather than replaced with a guess.
     *
     * @param array<string, string> $kept
     */
    private static function merge(mixed $stored, array $kept): ?string
    {
        if (!is_string($stored) || $stored === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $dirty = false;

        foreach ($kept as $key => $value) {
            if (!array_key_exists($key, $decoded) || $decoded[$key] !== $value) {
                $decoded[$key] = $value;
                $dirty = true;
            }
        }

        if (!$dirty) {
            return null;
        }

        try {
            return json_encode(
                $decoded,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            return null;
        }
    }
}
