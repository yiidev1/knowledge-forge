<?php

declare(strict_types=1);

namespace App\Order58\Client\Receipt;

use function strlen;

/**
 * One receipt, as bytes and a type we have already agreed to serve.
 *
 * ## Why HTML is not among them
 *
 * The provider's own implementation can return an HTML receipt, and this one refuses it on purpose.
 * Upstream HTML rendered on our origin is upstream script on our origin: it would read this
 * application's cookies, call its endpoints as the signed-in administrator, and sit outside the
 * `script-src 'self'` policy that every page here relies on. A sandboxed `<iframe>` with its own
 * `Content-Security-Policy: sandbox` response header is the supported way to show it, and it needs a
 * second route serving an isolated document — real work, not a flag, and nothing on this page asks for
 * it yet. Until something does, an HTML body is reported as unsupported rather than quietly rendered.
 *
 * Nothing here is cached and nothing is written to disk. A receipt is somebody's order, with their name
 * and address on it, so it lives in memory for the length of one request and no longer.
 */
final readonly class ReceiptDelivery
{
    public function __construct(
        public string $bytes,
        /** Already normalised and already checked against {@see ReceiptSettings::SUPPORTED_TYPES}. */
        public string $contentType,
    ) {}

    public function sizeBytes(): int
    {
        return strlen($this->bytes);
    }

    /**
     * What a browser should call the file if the reader saves it.
     *
     * Built from the order id rather than from anything upstream sent: a `filename` taken from a
     * provider header is a path the provider chose, and this one is a number we already validated.
     */
    public function downloadName(int $sourceOrderId): string
    {
        return 'receipt-' . $sourceOrderId . $this->extension();
    }

    private function extension(): string
    {
        return match ($this->contentType) {
            'image/png' => '.png',
            'image/jpeg' => '.jpg',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            'application/pdf' => '.pdf',
            default => '',
        };
    }
}
