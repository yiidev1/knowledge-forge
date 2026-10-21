<?php

declare(strict_types=1);

namespace App\Order58\Client\Receipt;

use RuntimeException;

use function sprintf;

/**
 * The Receipt API did not give us something we can show.
 *
 * Written to be read by an administrator on the receipt tab, so it names what went wrong and **never**
 * carries a credential, a request URI, a response header or a raw body. A provider's error page is
 * written by somebody else; forwarding it would put their words, and possibly their markup, on our
 * origin.
 *
 * The same vocabulary as {@see \App\Order58\Client\Orders\OrderDataFailed}, deliberately: two endpoints
 * of one API should fail in the same words.
 */
final class ReceiptFailed extends RuntimeException
{
    public static function notConfigured(string $problems): self
    {
        return new self('Receipts are not configured on this server: ' . $problems);
    }

    public static function refused(int $status): self
    {
        return new self(match (true) {
            $status === 401 || $status === 403 => 'The receipt service rejected this server\'s credentials '
                . '(HTTP ' . $status . '). The token may need rotating.',
            $status === 404 => 'The receipt service has no receipt for this order (HTTP 404). It may not '
                . 'have been printed yet.',
            $status === 429 => 'The receipt service is rate limiting this server (HTTP 429). Try again '
                . 'shortly.',
            $status >= 500 => 'The receipt service is unavailable (HTTP ' . $status . '). This is a fault '
                . 'at their end.',
            default => 'The receipt service refused the request (HTTP ' . $status . ').',
        });
    }

    public static function unreachable(): self
    {
        return new self('The receipt service could not be reached, or did not answer in time.');
    }

    public static function tooLarge(int $limitBytes): self
    {
        return new self(sprintf(
            'The receipt is larger than this server will read in one request (%d MB).',
            (int) ($limitBytes / 1048576),
        ));
    }

    public static function empty(): self
    {
        return new self('The receipt service returned an empty response.');
    }

    /**
     * An answer we are not prepared to serve.
     *
     * The type is named because an administrator reporting this to the provider needs to say what came
     * back — and a media type is not sensitive. Nothing of the body is included.
     */
    public static function unsupportedType(string $contentType): self
    {
        if ($contentType === '') {
            return new self('The receipt service did not say what kind of file it returned, so it was '
                . 'not displayed.');
        }

        return new self('The receipt service returned "' . $contentType . '", which this application '
            . 'does not display. Images and PDFs are supported.');
    }

    /** The order is ours and real, but the provider has nothing filed under it. */
    public static function notAvailable(): self
    {
        return new self('No receipt is available for this order.');
    }
}
