<?php

declare(strict_types=1);

namespace App\Order58\Application\CaseOrders;

use App\Order58\Domain\CaseOrders\CaseOrder;

use function count;

/**
 * What one response yielded: the orders that could be read, and what could not.
 *
 * Both halves are reported. A response where 198 of 200 orders imported and two were skipped is a
 * success with a note, not a silent partial — and the operator who paid for the request is exactly
 * the person who needs to know which two, and why, before tomorrow.
 */
final readonly class CaseOrderParseResult
{
    /**
     * @param list<CaseOrder> $orders
     * @param list<string>    $skipped one human-readable line per order or line not imported
     */
    public function __construct(
        public array $orders,
        public array $skipped,
        /** Set only when the response could not be read at all. */
        public ?string $fatal = null,
    ) {}

    public static function unreadable(string $why): self
    {
        return new self([], [], $why);
    }

    public function isReadable(): bool
    {
        return $this->fatal === null;
    }

    public function orderCount(): int
    {
        return count($this->orders);
    }

    /** Lines across every order that could be read, for the import report. */
    public function itemCount(): int
    {
        $n = 0;

        foreach ($this->orders as $order) {
            $n += $order->itemCount();
        }

        return $n;
    }
}
