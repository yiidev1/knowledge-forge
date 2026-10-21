<?php

declare(strict_types=1);

namespace App\Order58\Client\Receipt;

use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * `POST {ORDER58_RECEIPT_API_URL}` — one order's receipt.
 *
 * Built on {@see \App\Order58\Client\Orders\HttpOrderDataClient}, which is the only other Order58 call
 * that runs inside a web request, and it inherits that client's three decisions for the same reasons:
 *
 * - **No retries.** A POST whose idempotency the provider does not document, inside a request budget
 *   measured in seconds. A failure is reported; the administrator can press the link again.
 * - **The body is read with a ceiling.** A receipt is a small image, so an answer that is not small is
 *   not a receipt. The stream is read in chunks and abandoned the moment it passes the limit, before
 *   anything is held whole.
 * - **Nothing is logged and no body reaches an exception.** The Authorization header is the only place
 *   the token appears, and a receipt is somebody's order with their name on it.
 *
 * ## What comes back
 *
 * The provider may answer with the image itself, or with JSON naming where the image is. Both are
 * handled, and **the content type decides** — never the status code and never a guess. Anything else is
 * refused by type rather than sniffed, so an unexpected `text/html` is reported instead of forwarded.
 *
 * A JSON answer is followed exactly once, to an absolute `https://` URL, with the same ceiling and the
 * same allow-list applied to what it returns. One hop, because a redirect chain through an upstream we
 * do not control is a request we cannot bound.
 */
final readonly class HttpReceiptClient implements ReceiptClientInterface
{
    private const READ_CHUNK_BYTES = 262144;

    /** Keys a JSON answer might carry the image location under, in the order they are tried. */
    private const URL_KEYS = ['url', 'receipt_url', 'receipt', 'image', 'image_url', 'path'];

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private ReceiptSettings $settings,
    ) {}

    public function fetch(int $accountId, int $sourceOrderId): ReceiptDelivery
    {
        if (!$this->settings->isUsable()) {
            throw ReceiptFailed::notConfigured(implode(' ', $this->settings->problems()));
        }

        // Both are ints that the action has already matched against `order58_orders`, so there is
        // nothing here a caller could shape into something else. They are sent as strings because that
        // is what the orders endpoint of this same API takes.
        $body = json_encode([
            'account_id' => (string) $accountId,
            'order_id' => (string) $sourceOrderId,
        ], JSON_THROW_ON_ERROR);

        $request = $this->requestFactory
            ->createRequest('POST', $this->settings->url)
            ->withHeader('Authorization', $this->settings->authorizationHeader())
            ->withHeader('Content-Type', 'application/json')
            // Named rather than `*/*`: it tells the provider what we can actually render, and it is the
            // same list we check the answer against.
            ->withHeader('Accept', implode(', ', [...ReceiptSettings::SUPPORTED_TYPES, 'application/json']))
            ->withBody($this->streamFactory->createStream($body));

        $response = $this->send($request);
        $status = $response->getStatusCode();

        if ($status === 404) {
            throw ReceiptFailed::notAvailable();
        }

        if ($status < 200 || $status > 299) {
            throw ReceiptFailed::refused($status);
        }

        $declared = ReceiptSettings::normalise($response->getHeaderLine('Content-Type'));
        $body = $this->readBounded($response);

        /*
         * The bytes outrank the header, because this provider's header is wrong.
         *
         * It serves a real PNG labelled `application/json; charset=UTF-8`. Sniffing first is what
         * makes that work, and it costs nothing in safety: `sniff()` only ever answers with a type
         * already on the allow-list, so this can turn a mislabelled image into an image and can never
         * turn anything into HTML or script. A body it does not recognise falls through to the
         * declared type and is checked exactly as before.
         */
        return $this->deliver($body, $declared);
    }

    /**
     * A JSON answer names where the image is; this fetches it, once.
     *
     * The URL is required to be absolute and `https://`. A relative path would be resolved against our
     * own host and turn this into a request to ourselves, and a plain-http one would send a credentialed
     * redirect over the wire in clear — so neither is followed.
     */
    private function followJson(string $body): ReceiptDelivery
    {
        if ($body === '') {
            throw ReceiptFailed::empty();
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // The reason is deliberately not quoted: a JSON error message can echo the body back.
            throw ReceiptFailed::unsupportedType('application/json');
        }

        if (!is_array($decoded)) {
            throw ReceiptFailed::unsupportedType('application/json');
        }

        // A provider that says "no receipt" in a 200 body is saying 404 politely.
        foreach (['error', 'message'] as $key) {
            if (isset($decoded[$key]) && $decoded[$key] !== '' && !isset($decoded['url'])) {
                throw ReceiptFailed::notAvailable();
            }
        }

        $url = null;

        foreach (self::URL_KEYS as $key) {
            /** @var mixed $candidate */
            $candidate = $decoded[$key] ?? null;

            if (is_string($candidate) && str_starts_with($candidate, 'https://')) {
                $url = $candidate;

                break;
            }
        }

        if ($url === null) {
            throw ReceiptFailed::notAvailable();
        }

        $image = $this->send(
            $this->requestFactory->createRequest('GET', $url)
                ->withHeader('Accept', implode(', ', ReceiptSettings::SUPPORTED_TYPES)),
        );

        $status = $image->getStatusCode();

        if ($status < 200 || $status > 299) {
            throw ReceiptFailed::refused($status);
        }

        /*
         * The same gate, with one difference: a CDN answering this hop with more JSON is not another
         * link to follow. One hop is the whole budget, so the declared type is flattened to something
         * `deliver()` will refuse rather than recurse on.
         */
        $bytes = $this->readBounded($image);
        $type = ReceiptSettings::normalise($image->getHeaderLine('Content-Type'));

        return $this->deliver($bytes, $type === 'application/json' ? 'application/octet-stream' : $type);
    }

    /**
     * The last gate: **the bytes decide**, and the header is only a tie-breaker for JSON.
     *
     * The declared type is not trusted at all, in either direction:
     *
     * - This provider serves a real PNG labelled `application/json; charset=UTF-8` (measured
     *   2026-10-21), so believing the header refused a perfectly good receipt.
     * - And believing it the other way was worse: a body of HTML under a `Content-Type: image/png`
     *   header used to pass this check and be served, because only the label was ever examined. A
     *   test written for the first problem caught the second.
     *
     * So a receipt is served when, and only when, its first bytes carry the signature of a type on
     * {@see ReceiptSettings::SUPPORTED_TYPES}. Everything else is refused — except a body that is
     * genuinely JSON, which names where the image lives and is followed once.
     */
    private function deliver(string $bytes, string $declaredType): ReceiptDelivery
    {
        if ($bytes === '') {
            throw ReceiptFailed::empty();
        }

        $actual = ReceiptSettings::sniff($bytes);

        if ($actual !== null) {
            return new ReceiptDelivery($bytes, $actual);
        }

        if ($declaredType === 'application/json') {
            return $this->followJson($bytes);
        }

        // Not an image, not a PDF, not JSON. Reported by what it claimed to be, because that is the
        // useful thing to tell whoever has to ask the provider about it.
        throw ReceiptFailed::unsupportedType($declaredType);
    }

    private function send(\Psr\Http\Message\RequestInterface $request): ResponseInterface
    {
        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface) {
            // Deliberately swallowing the detail: a transport exception's message can carry the full
            // request URI, and this one is rendered on an admin page.
            throw ReceiptFailed::unreachable();
        }
    }

    /**
     * Reads the body, refusing rather than growing past the ceiling.
     *
     * The check is on bytes already read, so the request is abandoned part-way through a huge response
     * instead of after it — the whole point is never to hold the oversized thing in memory at all.
     */
    private function readBounded(ResponseInterface $response): string
    {
        $limit = $this->settings->maxResponseBytes;
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

                throw ReceiptFailed::tooLarge($limit);
            }
        }

        return $body;
    }
}
