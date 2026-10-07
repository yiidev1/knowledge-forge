<?php

declare(strict_types=1);

namespace App\OrderTesting\Application;

use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Domain\DemoOrderRepositoryInterface;
use App\OrderTesting\Domain\DemoOrderUpsert;
use App\OrderTesting\Domain\TestAttemptRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function file_get_contents;
use function filemtime;
use function is_readable;

/**
 * Reads the demo-order directory and writes what it finds, once.
 *
 * ## It is a reader of someone else's files
 *
 * The documents are written by a separate program — a Python WebSocket listener this application does
 * not own, deploy or coordinate with. So nothing here creates, renames, moves, truncates or deletes a
 * file, and every read tolerates its own file having vanished since the directory was listed. A listener
 * mid-write produces a document that does not parse, which is refused and picked up whole on the next
 * run; that is why a refusal is never fatal and never remembered.
 *
 * ## One bad file is one bad file
 *
 * Each document is parsed, validated and stored on its own. A malformed one is counted, named and
 * skipped — the run continues. The opposite arrangement, where the first unreadable file ends the pass,
 * means one corrupt document stops every later demo order from ever being imported.
 *
 * ## Attribution happens here, and only on the way in
 *
 * A demo order is credited to the open attempt for its store and source order **at the moment it is
 * first inserted**. Re-running the importer never moves attribution: an order that already exists keeps
 * the attempt it was given, so a rescan cannot hand one operator's work to whoever clicked most
 * recently. An order that arrives with nothing open stays unmatched, and the page says so rather than
 * guessing.
 */
final readonly class DemoOrderImporter
{
    public function __construct(
        private DemoOrderDirectory $directory,
        private DemoOrderRepositoryInterface $demoOrders,
        private TestAttemptRepositoryInterface $attempts,
        private OrderNormalizer $normalizer,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param int|null $limit stop after this many files, so one pass of a scheduled run is bounded
     */
    public function import(?int $limit = null): ImportReport
    {
        $report = new ImportReport();
        $status = $this->directory->resolve();

        if (!$status->isUsable()) {
            $report->refuse('(directory)', (string) $status->problem);

            return $report;
        }

        $now = $this->clock->now();

        // Before matching, so a window that closed while nobody was looking does not go on blocking the
        // Demo URL button or capturing an order placed long after the operator walked away.
        $report->expired = $this->attempts->expireOverdue($now);

        $directory = (string) $status->path;

        foreach ($this->directory->filesIn($directory) as $file) {
            if ($limit !== null && $report->scanned >= $limit) {
                break;
            }

            ++$report->scanned;

            $path = $directory . '/' . $file->basename;

            // Between listing and opening, the file may have been rotated away. That is normal and is
            // not a failure of this run.
            if (!is_readable($path)) {
                ++$report->unreadable;

                continue;
            }

            $contents = @file_get_contents($path);

            if ($contents === false) {
                ++$report->unreadable;

                continue;
            }

            $mtime = @filemtime($path);

            $document = DemoOrderDocument::parse(
                $file,
                $contents,
                $mtime === false ? null : $mtime,
                $this->normalizer,
            );

            if ($document->order === null) {
                $report->refuse($file->basename, (string) $document->refusal);
                // The filename and the gate, never the document. A refusal reason that quoted the
                // contents would put a customer's details in a log file.
                $this->logger->warning('Order Testing: demo order refused', [
                    'file' => $file->basename,
                    'reason' => $document->refusal,
                ]);

                continue;
            }

            $order = $document->order;

            try {
                $attempt = $this->attempts->openFor($order->storeSourceId, $order->sourceOrderId, $now);

                $result = $this->demoOrders->upsert($order, $attempt?->id);

                match ($result) {
                    DemoOrderUpsert::Inserted => ++$report->inserted,
                    DemoOrderUpsert::Updated => ++$report->updated,
                    DemoOrderUpsert::Unchanged => ++$report->unchanged,
                };

                // Only a genuinely new demo order closes the loop on an attempt. An update must not
                // re-credit, or a rescan would move somebody else's work onto a newer attempt.
                if ($result === DemoOrderUpsert::Inserted && $attempt !== null) {
                    $this->attempts->recordMatch($attempt->id, $order->demoOrderId, $now);
                    ++$report->matched;
                }
            } catch (Throwable $e) {
                // One row's problem is one row's problem. The run continues.
                ++$report->unreadable;
                $this->logger->error('Order Testing: demo order could not be stored', [
                    'file' => $file->basename,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Order Testing: demo order import finished', ['summary' => $report->summary()]);

        return $report;
    }
}
