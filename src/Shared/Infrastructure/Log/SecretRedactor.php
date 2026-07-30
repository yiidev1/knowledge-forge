<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Log;

use function is_array;
use function is_scalar;
use function is_string;

/**
 * Removes credentials from text before it is logged, persisted or shown to a user.
 *
 * This is a last line of defence, not the first. The first is never putting a secret into a message.
 * But provider error bodies, cURL verbose output and third-party exception messages routinely echo the
 * request that produced them, so every string that reaches a log target or `documents.error_message`
 * passes through here.
 */
final readonly class SecretRedactor
{
    public const PLACEHOLDER = '***redacted***';

    /**
     * Patterns are ordered from most specific to least, so a header match wins over a bare token match.
     *
     * @var list<non-empty-string>
     */
    private const PATTERNS = [
        // Authorization headers in any casing, with any scheme.
        '~(authorization\s*[:=]\s*)(?:bearer\s+)?[^\s"\',;]+~i',
        '~(proxy-authorization\s*[:=]\s*)(?:bearer\s+)?[^\s"\',;]+~i',
        // OpenAI-style keys, including project and service-account variants.
        '~\bsk-[A-Za-z0-9_\-]{8,}~',
        '~\bsess-[A-Za-z0-9_\-]{8,}~',
        // Common credential-bearing key/value shapes in JSON, query strings and DSNs.
        '~("?(?:api[_-]?key|apikey|access[_-]?token|refresh[_-]?token|client[_-]?secret|password|passwd|pwd)"?\s*[:=]\s*"?)[^\s"\',;&]+~i',
    ];

    /** @var list<string> */
    private array $literals;

    /**
     * @param list<string> $literals Exact secret strings to strike out, e.g. the configured API key and
     *                               database password. Empty strings are ignored so an unset credential
     *                               does not turn into a match-everything rule.
     */
    public function __construct(array $literals = [])
    {
        $filtered = [];

        foreach ($literals as $literal) {
            // Very short values are not distinctive enough to redact without mangling ordinary text.
            if (strlen($literal) >= 6) {
                $filtered[] = $literal;
            }
        }

        // Longest first, so a secret that contains another secret as a prefix is fully removed.
        usort($filtered, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        $this->literals = $filtered;
    }

    public function redact(string $text): string
    {
        foreach ($this->literals as $literal) {
            $text = str_replace($literal, self::PLACEHOLDER, $text);
        }

        foreach (self::PATTERNS as $pattern) {
            // $1 is the retained label ("Authorization: ", "api_key": ) where the pattern defines one.
            $replaced = preg_replace($pattern, '${1}' . self::PLACEHOLDER, $text);

            // A PCRE failure (backtrack limit on a pathological input) must not silently pass the
            // unredacted text through, so fall back to discarding the message body.
            if ($replaced === null) {
                return self::PLACEHOLDER;
            }

            $text = $replaced;
        }

        return $text;
    }

    /**
     * Redacts every string in a log context, recursively.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $context
     *
     * @return array<TKey, mixed>
     */
    public function redactContext(array $context): array
    {
        $result = [];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->redactContext($value);
                continue;
            }

            if (is_string($value)) {
                $result[$key] = $this->redact($value);
                continue;
            }

            $result[$key] = is_scalar($value) || $value === null ? $value : '<object>';
        }

        return $result;
    }

    /**
     * Truncates after redacting, for columns such as `documents.error_message`.
     */
    public function redactAndTruncate(string $text, int $maxLength): string
    {
        $redacted = $this->redact($text);

        return mb_strlen($redacted) <= $maxLength
            ? $redacted
            : mb_substr($redacted, 0, $maxLength - 1) . '…';
    }
}
