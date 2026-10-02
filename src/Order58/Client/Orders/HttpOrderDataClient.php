<?php

declare(strict_types=1);

namespace App\Order58\Client\Orders;

use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function is_array;
use function json_decode;
use function json_encode;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * `POST {ORDER58_ORDERS_API_URL}` — a store's orders for a date range.
 *
 * Deliberately **not** part of {@see \App\Order58\Client\HttpOrder58Client}. That client is GET-only,
 * bound to the accounts host, and its own docblock records the assumption that every call it makes runs
 * in the cron worker and "can afford patient timeouts". None of those three things is true here: this is
 * a POST, to a different host, inside a web request with a few seconds to spare.
 *
 * ## No retries, on purpose
 *
 * Every other Order58 call is a GET and retries safely. This is a POST whose idempotency the provider
 * does not document, and it runs inside a request budget measured in seconds — so a retry would risk
 * doubling a large request to save a failure the operator can repeat with one click. A failure here is
 * reported, not re-sent.
 *
 * ## The body is read with a ceiling
 *
 * PHP's memory limit on this deployment is 128M and a decoded order costs roughly four times its encoded
 * size, so an unbounded read is an out-of-memory fatal waiting for a busy store — and a fatal mid-sync is
 * exactly the outcome that leaves somebody unsure what was saved. The stream is read in chunks and
 * abandoned the moment it passes the configured ceiling, before anything is decoded.
 *
 * The Authorization header is the only place the token appears. Nothing here logs, and no response body
 * is ever carried into an exception message.
 */
final readonly class HttpOrderDataClient implements OrderDataClientInterface
{
    private const READ_CHUNK_BYTES = 262144;

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private OrderDataSettings $settings,
    ) {}

    public function listOrders(int $accountId, string $dateFrom, string $dateTo): array
    {
        if (!$this->settings->isUsable()) {
            throw OrderDataFailed::notConfigured(implode(' ', $this->settings->problems()));
        }

        // The account id is an int and the dates have already been through OrderDateRange, so there is
        // nothing here a caller could shape into something else.
        $body = json_encode([
            'account_id' => (string) $accountId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ], JSON_THROW_ON_ERROR);

        $request = $this->requestFactory
            ->createRequest('POST', $this->settings->url)
            ->withHeader('Authorization', $this->settings->authorizationHeader())
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface) {
            // Deliberately swallowing the detail: a transport exception's message can carry the full
            // request URI, and this one is rendered on an admin page.
            throw OrderDataFailed::unreachable();
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            throw OrderDataFailed::refused($status);
        }

        return $this->decodeOrders($this->readBounded($response));
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

                throw OrderDataFailed::tooLarge($limit);
            }
        }

        return $body;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function decodeOrders(string $body): array
    {
        if ($body === '') {
            throw OrderDataFailed::malformed('the response was empty');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw OrderDataFailed::malformed('the body was not valid JSON (' . $e->getMessage() . ')');
        }

        if (!is_array($decoded) || !isset($decoded['Orders'])) {
            throw OrderDataFailed::malformed('the body carried no "Orders" key');
        }

        if (!is_array($decoded['Orders'])) {
            throw OrderDataFailed::malformed('"Orders" was not a list');
        }

        $orders = [];
        foreach ($decoded['Orders'] as $order) {
            // A non-object entry is skipped here rather than refused: one malformed row must not cost the
            // operator the other 199. The sync counts what it could not use.
            if (is_array($order)) {
                $orders[] = $order;
            }
        }

        return $orders;
    }
}
