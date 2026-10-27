<?php

declare(strict_types=1);

namespace App\Order58\Application\CallRecordings;

use App\Order58\Domain\CallRecordings\ListedCall;

/** What {@see CallListParser} read: the usable sessions, and how many links it had to drop. */
final readonly class ParsedCalls
{
    /**
     * @param list<ListedCall> $calls
     */
    public function __construct(
        public array $calls,
        public int $rejectedLinks,
    ) {}
}
