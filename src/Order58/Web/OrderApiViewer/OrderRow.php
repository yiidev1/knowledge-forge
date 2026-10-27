<?php

declare(strict_types=1);

namespace App\Order58\Web\OrderApiViewer;

use function is_array;
use function is_scalar;
use function is_string;
use function trim;

/**
 * The six fields this page shows, and nothing else.
 *
 * ## The whitelist is the security boundary
 *
 * The provider's record carries about seventy fields — `payment_method`, `customer_id`,
 * `credit_card_collection`, the full `items` list and more. None of that is this page's business, and
 * the cheapest way to guarantee it never reaches a browser is to never put it in the object the
 * template renders. So this is built by **naming the six wanted fields**, not by removing unwanted ones:
 * a field added to the provider's response later appears here only if somebody writes it in.
 *
 * The upstream client has already redacted payment secrets before this sees anything. This is the second
 * fence, not the first, and it exists because the first one protects staging and logging rather than
 * this page's HTML.
 *
 * ## Values are carried verbatim
 *
 * `total_cost` arrives as `"69.05000"` and is kept that way. Rounding it here would make the page
 * disagree with the provider about money, and a viewer whose job is to show what the API returned has
 * no business improving it.
 */
final readonly class OrderRow
{
    /**
     * @param list<RecordingRef> $recordings zero or more; one Play button each
     */
    public function __construct(
        public string $id,
        public string $seq,
        public string $accountId,
        public string $totalCost,
        public string $sessionId,
        public array $recordings,
    ) {}

    /**
     * @param array<array-key, mixed> $order one sanitized provider record
     */
    public static function fromProviderRecord(array $order): self
    {
        $calls = $order['calls'] ?? null;
        $calls = is_array($calls) ? $calls : [];

        $sessionId = self::text($calls['session_id'] ?? null);
        $urls = $calls['wav_download_urls'] ?? null;

        $recordings = [];

        if (is_array($urls)) {
            foreach ($urls as $url) {
                if (!is_string($url)) {
                    continue;
                }

                // A link the provider sent that does not point at the recording host is dropped rather
                // than rendered. It would not play, and a Play button that cannot play is worse than
                // the "No Recording" the row falls back to.
                $ref = RecordingRef::fromProviderUrl($url, $sessionId === '' ? null : $sessionId);

                if ($ref !== null) {
                    $recordings[] = $ref;
                }
            }
        }

        return new self(
            self::text($order['id'] ?? null),
            self::text($order['seq'] ?? null),
            self::text($order['account_id'] ?? null),
            self::text($order['total_cost'] ?? null),
            $sessionId,
            $recordings,
        );
    }

    public function hasRecordings(): bool
    {
        return $this->recordings !== [];
    }

    /** Null, a number, a nested array — all become a string, and a missing value becomes ''. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
