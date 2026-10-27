<?php

declare(strict_types=1);

namespace App\Order58\Application\CallRecordings;

use App\Integration\Order58Recording\RecordingUrlPolicy;
use App\Order58\Domain\CallRecordings\ListedCall;

use function array_is_list;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function trim;

/**
 * An order's `calls` value, read into call sessions and their WAV links.
 *
 * Today the provider sends one object, `{"session_id": "…", "wav_download_urls": ["…"]}`. A list of
 * such objects is accepted as well — it is the obvious way an order with two calls will be sent — so
 * the day that happens costs nothing.
 *
 * ## What is accepted, and what is dropped
 *
 * - A missing or `null` `calls`, or any other shape, is **no calls**. Not an error: most orders were
 *   never called about, and an order is still an order without a recording.
 * - A session id must be digits. A session without one cannot be stored or played, so its links are
 *   counted as rejected rather than filed under a guess — `order58_orders.call_id` is a different value
 *   and is never substituted.
 * - Each link is kept **exactly** as sent, or not at all: anything {@see RecordingUrlPolicy} refuses is
 *   counted and dropped. The URL itself never appears in what this reports — it holds a phone number.
 * - The same link twice is one link. The same session twice is one session, its links in order.
 */
final readonly class CallListParser
{
    /**
     * @param mixed $calls the raw `calls` value of one order
     */
    public function parse(mixed $calls): ParsedCalls
    {
        if (!is_array($calls) || $calls === []) {
            return new ParsedCalls([], 0);
        }

        // One object, or a list of them.
        $entries = array_is_list($calls) ? $calls : [$calls];

        /** @var array<string, list<string>> $bySession */
        $bySession = [];
        $order = [];
        $rejected = 0;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $urls = $entry['wav_download_urls'] ?? null;
            $urls = is_array($urls) ? $urls : [];
            $sessionId = $this->sessionId($entry['session_id'] ?? null);

            if ($sessionId === null) {
                $rejected += count($urls);

                continue;
            }

            if (!isset($bySession[$sessionId])) {
                $bySession[$sessionId] = [];
                $order[] = $sessionId;
            }

            foreach ($urls as $url) {
                if (!is_string($url) || !RecordingUrlPolicy::accepts($url)) {
                    ++$rejected;

                    continue;
                }

                if (!in_array($url, $bySession[$sessionId], true)) {
                    $bySession[$sessionId][] = $url;
                }
            }
        }

        $listed = [];

        foreach ($order as $sessionId) {
            $listed[] = new ListedCall($sessionId, $bySession[$sessionId]);
        }

        return new ParsedCalls($listed, $rejected);
    }

    /** Digits, from a string or an integer; anything else is no session id at all. */
    private function sessionId(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/\A\d{1,20}\z/', $value) === 1 ? $value : null;
    }
}
