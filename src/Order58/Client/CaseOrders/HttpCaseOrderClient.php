<?php

declare(strict_types=1);

namespace App\Order58\Client\CaseOrders;

use App\Shared\Order58\PaymentSecretRedactor;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use DateTimeImmutable;
use DateTimeZone;

use function implode;
use function is_array;
use function json_decode;
use function json_encode;
use function preg_match;
use function strlen;
use function time;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The day's one request to `get-case-order-mixed`, over HTTPS, redacted before it is returned.
 *
 * ## Redaction happens here, not later
 *
 * The response carries `card_num`, `exp_date` and a CVV for every credit-card order. The body is
 * **staged** so the import can be retried without another call, so an unredacted body would be a
 * table of card numbers sitting in the database — worse than the transient copy it replaced.
 *
 * So nothing leaves this class unsanitized. The returned `sanitizedBody` is re-encoded from the
 * stripped document, and it is that string the caller stages. The only unsanitized copy is the local
 * variable the decode read, which the redaction immediately replaces.
 *
 * ## Bounded reading, for a response the provider has shown at over a megabyte
 *
 * The body is read in chunks and abandoned the moment it passes the ceiling, rather than after — the
 * point is never to hold an unbounded response in memory at all. A provider that one day answers with
 * a gigabyte must not take the application down with it.
 *
 * ## Two authentication modes, because only one of them is confirmed
 *
 * The provider's own PHP example puts the credential in the query string; a Postman capture that
 * returned 200 used a bearer header. {@see CaseOrderAuth} holds both and the configured one is used,
 * so if the provider changes its mind this is a setting rather than an edit. The credential never
 * appears in an exception, a log or this class's own error text — see {@see CaseOrderFetchFailed}.
 */
final readonly class HttpCaseOrderClient implements CaseOrderClientInterface
{
    /** A week. A `Retry-After` beyond this is treated as unreadable rather than obeyed. */
    private const MAX_RETRY_AFTER = 604800;

    /** The three date spellings RFC 7231 permits, and no others. */
    private const HTTP_DATE_FORMATS = [
        'D, d M Y H:i:s \G\M\T',  // IMF-fixdate, the one every modern server sends
        'l, d-M-y H:i:s \G\M\T',  // RFC 850, obsolete but still legal
        'D M j H:i:s Y',            // asctime, likewise
    ];

    private const READ_CHUNK_BYTES = 262144;

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private CaseOrderSettings $settings,
    ) {}

    public function fetch(): CaseOrderResponse
    {
        // Checked before the socket, so a missing token costs a message rather than the day's only
        // request. The caller claims the day only after this has passed.
        if (!$this->settings->isUsable()) {
            throw CaseOrderFetchFailed::notConfigured(implode(' ', $this->settings->problems()));
        }

        $request = $this->requestFactory
            ->createRequest('POST', $this->settings->authorizedUrl())
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            // An empty JSON object, which is what the provider's example sends. There is nothing to
            // ask about: the endpoint answers for everything.
            ->withBody($this->streamFactory->createStream('{}'));

        $authorization = $this->settings->authorizationHeader();

        if ($authorization !== null) {
            $request = $request->withHeader('Authorization', $authorization);
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface) {
            /*
             * The detail is deliberately discarded.
             *
             * A transport exception's message routinely contains the full request URI, and in query
             * mode that URI contains the credential. This message is stored and rendered.
             */
            throw CaseOrderFetchFailed::unreachable();
        }

        $status = $response->getStatusCode();

        if ($status === 429) {
            // The provider's own limit, told to us rather than assumed. Carried out of here so the
            // run can record when it said to come back; nothing here retries.
            throw CaseOrderFetchFailed::rateLimited($status, $this->retryAfter($response));
        }

        if ($status < 200 || $status > 299) {
            throw CaseOrderFetchFailed::refused($status);
        }

        $body = $this->readBounded($response);
        $bytes = strlen($body);

        return $this->sanitize($body, $status, $bytes);
    }

    /**
     * `Retry-After`, in seconds, when the provider sent one we can actually read.
     *
     * The header has two legal forms: a count of seconds, and an HTTP date. Both are accepted, and
     * anything else — a malformed value, a date already in the past, an absurd span — yields null
     * rather than a guess. A null means "it did not say", which the caller reports as such; it never
     * becomes a default wait, because a limit this application invented is exactly what was removed.
     *
     * Capped at a week so a mistyped header cannot park the feature indefinitely.
     */
    private function retryAfter(ResponseInterface $response): ?int
    {
        $raw = trim($response->getHeaderLine('Retry-After'));

        if ($raw === '') {
            return null;
        }

        if (preg_match('/\A\d{1,7}\z/', $raw) === 1) {
            $seconds = (int) $raw;

            return $seconds > 0 && $seconds <= self::MAX_RETRY_AFTER ? $seconds : null;
        }

        /*
         * The date form is parsed against the formats RFC 7231 actually permits, never `strtotime`.
         *
         * `strtotime` is far too willing: it reads `-5` as a five-hour offset, so a malformed header
         * would become a five-hour wait this application invented and attributed to the provider.
         * An explicit format list accepts the three legal spellings and rejects everything else.
         */
        foreach (self::HTTP_DATE_FORMATS as $format) {
            $at = DateTimeImmutable::createFromFormat($format, $raw, new DateTimeZone('GMT'));

            if ($at === false) {
                continue;
            }

            $seconds = $at->getTimestamp() - time();

            return $seconds > 0 && $seconds <= self::MAX_RETRY_AFTER ? $seconds : null;
        }

        return null;
    }

    /**
     * Reads the body, refusing rather than growing past the ceiling.
     *
     * The check is on bytes already read, so an oversized response is abandoned part-way through
     * instead of after it.
     */
    private function readBounded(ResponseInterface $response): string
    {
        $limit = CaseOrderSettings::MAX_RESPONSE_BYTES;
        $stream = $response->getBody();
        $body = '';

        while (!$stream->eof()) {
            $chunk = $stream->read(self::READ_CHUNK_BYTES);

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;

            if (strlen($body) > $limit) {
                unset($body);

                throw CaseOrderFetchFailed::tooLarge($limit);
            }
        }

        return $body;
    }

    /**
     * Decode, strip every payment secret, and re-encode.
     *
     * Each order is redacted **as a record in its own right**, which is the shape
     * {@see PaymentSecretRedactor} is written for: it decodes the nested JSON strings it knows about
     * and strips the five keys at every depth. The whole document is then redacted again, so a secret
     * sitting outside an order — in an envelope the provider adds later — is caught as well.
     *
     * The nested `items` are reached by the recursive strip, so a card number inside a line is
     * removed too.
     */
    private function sanitize(string $body, int $status, int $bytes): CaseOrderResponse
    {
        if ($body === '') {
            throw CaseOrderFetchFailed::empty();
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Not the message: a JSON error can quote the document it failed on.
            throw CaseOrderFetchFailed::malformed();
        }

        // The raw body is not needed again, and it is the one copy with card numbers in it.
        unset($body);

        if (!is_array($decoded)) {
            throw CaseOrderFetchFailed::malformed();
        }

        $envelope = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;

        $orders = [];

        foreach ($envelope as $order) {
            if (is_array($order)) {
                $orders[] = PaymentSecretRedactor::redact($order);
            }
        }

        return new CaseOrderResponse(
            httpStatus: $status,
            responseBytes: $bytes,
            // Re-encoded from the stripped orders, so what gets staged is the sanitized document and
            // the shape the parser reads is the shape that was stored.
            sanitizedBody: json_encode(
                $orders,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            orders: $orders,
        );
    }
}
