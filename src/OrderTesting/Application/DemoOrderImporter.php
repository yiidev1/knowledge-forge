<?php

declare(strict_types=1);

namespace App\OrderTesting\Application;

use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Domain\DemoOrderRepositoryInterface;
use App\OrderTesting\Domain\DemoOrderUpsert;
use App\OrderTesting\Domain\TestAttemptRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
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
 * A demo order is credited to the attempt that was running **when the provider says it was created**,
 * and only on the way in. Re-running the importer never moves attribution: an order that already
 * exists keeps the attempt it was given, so a rescan cannot hand one operator's work to whoever
 * clicked most recently.
 *
 * Attribution is refused rather than guessed. An order whose creation instant falls inside no
 * attempt's window — or inside more than one — stays unmatched, and the page says so. The filenames
 * carry a source order id and a demo order id and nothing about who was at the keyboard, so there is
 * no second source of truth to fall back on.
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
     * @param int|null $limit stop after this many files, so one pass stays bounded on a directory
     *                        that has accumulated more than somebody wants to wait for
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
                /*
                 * Who was practising when THIS order was created — not who happens to be practising
                 * now.
                 *
                 * This used to ask `openFor(..., $now)`: the attempt open at **import** time. Imports
                 * are manual and the measured gap between an order being created and the button being
                 * pressed is more than a day, while an attempt lives sixty minutes. So that lookup
                 * could only ever match by coincidence — and when it did match it would credit a
                 * two-day-old order to whoever had clicked Practice Order in the last hour. A
                 * confidently wrong training record is worse than an honest blank.
                 *
                 * The demo JSON carries no agent id, no attempt id and no correlation token, so the
                 * provider's own creation timestamp is the only evidence there is. An order that
                 * arrives without one cannot be attributed at all, and says so by staying unmatched.
                 */
                $attempt = $order->orderCreatedAt === null
                    ? null
                    : $this->attempts->attemptCoveringInstant(
                        $order->storeSourceId,
                        $order->sourceOrderId,
                        (new DateTimeImmutable('@' . $order->orderCreatedAt))
                            ->setTimezone(new DateTimeZone('UTC')),
                    );

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
