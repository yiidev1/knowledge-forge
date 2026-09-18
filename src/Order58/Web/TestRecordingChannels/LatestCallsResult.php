<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingChannels;

use function array_filter;
use function array_values;
use function count;
use function preg_replace;
use function strlen;
use function substr;

/**
 * What the Latest Calls request came back with, reduced to what the page may print.
 *
 * The raw body is kept because a failed request's own words are the diagnostic — the provider's
 * `403 Forbidden - IP not authorized: …` is more useful than any sentence this application could write
 * about it. It is bounded and stripped of control characters before it reaches HTML, and no response
 * header is carried at all: this tool sends no credential, so there is none to leak, and a header dump
 * is a standing invitation to print something a provider decides to start sending.
 */
final readonly class LatestCallsResult
{
    /**
     * @param list<CallSummary> $calls every decoded row, including any this page cannot offer
     */
    public function __construct(
        public string $url,
        public int $status,
        public string $reason,
        public array $calls,
        public ChannelDiagnosis $diagnosis,
        /** The first bytes of the body. Never rendered raw — use {@see bodyPreview()}. */
        public string $rawBody,
    ) {}

    /**
     * The rows a "Use this call" button may be offered for.
     *
     * A row with no session id, or with one the channel form would refuse, is shown in the table but
     * cannot be selected — so a click can never pre-fill a value the next screen rejects.
     *
     * @return list<CallSummary>
     */
    public function selectableCalls(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn(CallSummary $call): bool => $call->isUsable() && $call->hasValidRecordingId(),
        ));
    }

    public function hasCalls(): bool
    {
        return $this->calls !== [];
    }

    /** Rows the provider sent that cannot be used, so the page can say so rather than silently dropping them. */
    public function unusableCount(): int
    {
        return count($this->calls) - count($this->selectableCalls());
    }

    /**
     * A bounded, control-character-stripped look at the body.
     *
     * Shown for a failure, and on success only when nothing could be decoded — a readable list of calls
     * is already on screen, and printing its JSON underneath would be noise.
     */
    public function bodyPreview(int $maxBytes = 2000): ?string
    {
        if ($this->rawBody === '') {
            return null;
        }

        $clean = (string) preg_replace('/[^\P{C}\n\r\t]/u', '', $this->rawBody);

        if ($clean === '') {
            return null;
        }

        return strlen($clean) > $maxBytes ? substr($clean, 0, $maxBytes) . "\n…" : $clean;
    }
}
