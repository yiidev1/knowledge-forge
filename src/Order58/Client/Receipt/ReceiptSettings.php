<?php

declare(strict_types=1);

namespace App\Order58\Client\Receipt;

use SensitiveParameter;

use function in_array;
use function strtolower;
use function trim;

/**
 * Where the Receipt API is, what it costs us to wait for it, and what it is allowed to return.
 *
 * Its own settings object rather than a reused {@see \App\Order58\Client\Orders\OrderDataSettings},
 * following the rule that object's own docblock states: **one endpoint, one credential, one place to
 * rotate it**. The two share a host today and may not tomorrow, and a receipt credential that leaked
 * must be revocable without taking the nightly order sync down with it.
 *
 * The token is a constructor argument marked sensitive so it never appears in a stack trace, and it is
 * read exactly once, in {@see HttpReceiptClient}, to build the Authorization header.
 */
final readonly class ReceiptSettings
{
    /**
     * What this application is willing to hand to a browser from an upstream it does not control.
     *
     * An allow-list, not a block-list. The upstream decides what it sends; we decide what we are
     * prepared to render, and anything outside this list is refused with a message rather than
     * forwarded — which is what stops an unexpected `text/html` or `application/javascript` from
     * becoming a way to run someone else's script on our origin.
     *
     * HTML is deliberately **absent**. See {@see ReceiptDelivery} for what would have to be true
     * before it could be added.
     */
    public const SUPPORTED_TYPES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'application/pdf',
    ];

    public function __construct(
        public string $url,
        #[SensitiveParameter]
        private string $token,
        public int $connectTimeoutSeconds,
        public int $timeoutSeconds,
        public int $maxResponseBytes,
    ) {}

    /** Usable means configured — checked without opening a socket. */
    public function isUsable(): bool
    {
        return $this->url !== '' && $this->token !== '';
    }

    /**
     * The variable names an administrator would have to set. **Names only, never values.**
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->url === '') {
            $problems[] = 'ORDER58_RECEIPT_API_URL is not set.';
        }

        if ($this->token === '') {
            $problems[] = 'ORDER58_RECEIPT_API_TOKEN is not set.';
        }

        return $problems;
    }

    /** The single place the token is revealed. */
    public function authorizationHeader(): string
    {
        return 'Bearer ' . $this->token;
    }

    /**
     * Is this something we will pass on?
     *
     * The header may carry parameters (`image/png; charset=binary`), so only the type itself is
     * compared, lower-cased — a provider that changes its charset must not break the page.
     */
    public static function supports(string $contentType): bool
    {
        return in_array(self::normalise($contentType), self::SUPPORTED_TYPES, true);
    }

    /**
     * What the bytes actually are, when the header cannot be believed.
     *
     * Measured against the live endpoint on 2026-10-21: it answers a real PNG — correct
     * `\x89PNG\r\n\x1a\n` signature, ~96 KB — under `Content-Type: application/json; charset=UTF-8`.
     * Trusting the header sent those bytes to the JSON parser, which refused them, and the receipt
     * was reported as an unsupported type when it was a perfectly good image.
     *
     * ## This is narrower than sniffing, not wider
     *
     * It recognises exactly the signatures of the types already in {@see SUPPORTED_TYPES} and returns
     * `null` for everything else. There is no path from here to a type we would not already serve, so
     * a body of HTML or script is still refused — it simply matches nothing. The answer then becomes
     * the `Content-Type` we send ourselves, alongside `X-Content-Type-Options: nosniff`, so the
     * browser is told exactly one thing and does no guessing of its own.
     *
     * Signatures only, read from the first bytes; nothing is decoded and nothing is parsed.
     */
    public static function sniff(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'image/gif';
        }

        // RIFF....WEBP — the four length bytes sit between the two markers.
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        if (str_starts_with($bytes, '%PDF-')) {
            return 'application/pdf';
        }

        return null;
    }

    /** The media type alone, without parameters, lower-cased. */
    public static function normalise(string $contentType): string
    {
        $type = $contentType;
        $semicolon = strpos($type, ';');

        if ($semicolon !== false) {
            $type = substr($type, 0, $semicolon);
        }

        return strtolower(trim($type));
    }
}
