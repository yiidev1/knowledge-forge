<?php

declare(strict_types=1);

namespace App\Order58\Web\RecentCalls;

use App\Integration\Order58Recording\CallSummary;
use App\Integration\Order58Recording\ChannelDiagnosis;

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
     */
    public function __construct(
        public array $calls,
        public ?ChannelDiagnosis $problem,
    ) {}

    public static function empty(): self
    {
        return new self([], null);
    }

    public static function failed(ChannelDiagnosis $problem): self
    {
        return new self([], $problem);
    }
}
