<?php

declare(strict_types=1);

namespace App\Order58\Web\OrderApiViewer;

use function count;

/**
 * What one load produced: rows, or a sentence explaining why there are none.
 *
 * A failure is a value here rather than an exception, because the page renders either outcome and
 * neither is exceptional — an endpoint this page does not control is allowed to be down, and the whole
 * point of the viewer is to say so plainly rather than to show a stack trace.
 */
final readonly class FeedResult
{
    /**
     * @param list<OrderRow> $rows
     */
    private function __construct(
        public array $rows,
        public ?string $error,
        public ?int $httpStatus,
        public ?int $responseBytes,
        public ?int $ordersReceived,
    ) {}

    /**
     * @param list<OrderRow> $rows
     */
    public static function loaded(array $rows, int $httpStatus, int $responseBytes, int $ordersReceived): self
    {
        return new self($rows, null, $httpStatus, $responseBytes, $ordersReceived);
    }

    public static function failed(string $error): self
    {
        return new self([], $error, null, null, null);
    }

    public function succeeded(): bool
    {
        return $this->error === null;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /** How many of the loaded rows actually have something to play. */
    public function playableCount(): int
    {
        $n = 0;

        foreach ($this->rows as $row) {
            if ($row->hasRecordings()) {
                $n++;
            }
        }

        return $n;
    }
}
