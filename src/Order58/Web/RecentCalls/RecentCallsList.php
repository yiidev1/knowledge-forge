<?php

declare(strict_types=1);

namespace App\Order58\Web\RecentCalls;

use App\Integration\Order58Recording\CallSummary;
use App\Integration\Order58Recording\ChannelDiagnosis;

use function array_key_exists;

/**
 * What {@see RecentCallsLoader} found: the calls, or the reason there are none to show.
 *
 * `$problem` is the shared diagnosis's own headline and advice — sentences this application wrote — and
 * never the provider's body or the request URL.
 */
final readonly class RecentCallsList
{
    /**
     * @param list<CallSummary> $calls newest first
     * @param array<int, true> $mirroredOrders listed order ids this application has mirrored
     */
    public function __construct(
        public array $calls,
        public ?ChannelDiagnosis $problem,
        private array $mirroredOrders = [],
    ) {}

    public static function empty(): self
    {
        return new self([], null);
    }

    public static function failed(ChannelDiagnosis $problem): self
    {
        return new self([], $problem);
    }

    public function hasReceipt(CallSummary $call): bool
    {
        return $call->orderId !== '' && array_key_exists((int) $call->orderId, $this->mirroredOrders)
            && (string) (int) $call->orderId === $call->orderId;
    }
}
