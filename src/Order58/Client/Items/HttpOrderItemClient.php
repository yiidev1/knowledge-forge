<?php

declare(strict_types=1);

namespace App\Order58\Client\Items;

use App\Shared\Order58\PaymentSecretRedactor;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function array_key_exists;
use function array_map;
use function count;
use function implode;
use function is_array;
use function is_int;
use function json_decode;
use function json_encode;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * `POST {ORDER58_ITEM_API_URL}` — one order's line items.
 *
 * Modelled on {@see \App\Order58\Client\Orders\HttpOrderDataClient}, which is the sibling endpoint, and
 * it inherits that client's three deliberate decisions:
 *
 * **No retries.** A POST whose idempotency the provider does not document, called from a worker that
 * already has backoff and an attempt cap. Retrying inside the client would multiply a failure the
 * queue is designed to handle once.
 *
 * **The body is read with a ceiling.** Read in chunks and abandoned the moment it passes the configured
 * limit, before anything is decoded, so a pathological response cannot exhaust memory mid-sync.
 *
 * **The Authorization header is the only place the token appears.** Nothing here logs, and no response
 * body is carried into an exception message.
 *
 * ## What it refuses, and why that matters more here than for orders
 *
 * An items response decides whether existing item rows are **deleted and replaced**. So anything short
 * of a complete, well-formed answer is an error rather than an empty list:
 *
 *  - no `OrderItems` key, or one that is not an array → malformed
 *  - an element that is not an object → malformed, because a partial set would silently drop a line
 *  - **any continuation marker** (`next`, `cursor`, `has_more`, `next_page`, `total` exceeding the
 *    returned count) → incomplete. The provider has documented no paging protocol and this will not
 *    guess at one; treating a first page as the whole order would delete every item after it.
 *
 * Only `{"OrderItems": []}` with none of the above means "this order has no items".
 */
final readonly class HttpOrderItemClient implements OrderItemClientInterface
{
    private const READ_CHUNK_BYTES = 262144;

    /** Keys whose presence means the provider is offering more than it returned. */
    private const CONTINUATION_KEYS = ['next', 'next_page', 'cursor', 'has_more', 'more', 'page_token'];

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private OrderItemSettings $settings,
    ) {}

    public function listItems(int $accountId, int $sourceOrderId): array
    {
        // Two gates before any socket: the kill switch, then configuration. Kept apart so an operator
        // can tell "switched off" from "never set up".
        if (!$this->settings->isEnabled()) {
            throw OrderItemDataFailed::disabled();
        }

        if (!$this->settings->isUsable()) {
            throw OrderItemDataFailed::notConfigured(implode(' ', $this->settings->problems()));
        }

        // Both are ints by signature, so there is nothing here a caller could shape into something
        // else. Sent as strings because that is the shape the provider's own sample uses.
        $body = json_encode([
            'account_id' => (string) $accountId,
            'order_id' => (string) $sourceOrderId,
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
            // request URI, and this one is stored in items_error and rendered on an admin page.
            throw OrderItemDataFailed::unreachable();
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            throw OrderItemDataFailed::refused($status);
        }

        return $this->decode($this->readBounded($response));
    }

    /**
     * Reads the body, refusing rather than growing past the ceiling.
     *
     * The check is on bytes already read, so an oversized response is abandoned part-way through
     * instead of after it — the point is never to hold the whole thing in memory at all.
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

                throw OrderItemDataFailed::tooLarge($limit);
            }
        }

        return $body;
    }

    /**
     * @return array{items: list<array<array-key, mixed>>, body: string}
     */
    private function decode(string $body): array
    {
        if ($body === '') {
            throw OrderItemDataFailed::malformed('the response was empty');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // A truncated body lands here: it is not valid JSON, so it can never be mistaken for an
            // order that legitimately has no items.
            throw OrderItemDataFailed::malformed('the body was not valid JSON (' . $e->getMessage() . ')');
        }

        if (!is_array($decoded) || !array_key_exists('OrderItems', $decoded)) {
            throw OrderItemDataFailed::malformed('the body carried no "OrderItems" key');
        }

        if (!is_array($decoded['OrderItems'])) {
            // Covers `"OrderItems": null` and `"OrderItems": "invalid"`, both of which would otherwise
            // read as an empty order and delete every stored item.
            throw OrderItemDataFailed::malformed('"OrderItems" was not a list');
        }

        $items = [];
        foreach ($decoded['OrderItems'] as $item) {
            if (!is_array($item)) {
                // Refused outright rather than skipped. The orders client can afford to drop one bad
                // row out of two hundred; here the result replaces a snapshot, so a quietly shorter
                // list is a quietly deleted line item.
                throw OrderItemDataFailed::malformed('an entry in "OrderItems" was not an object');
            }

            $items[] = $item;
        }

        $this->refuseIfIncomplete($decoded, count($items));

        // Redaction happens at BOTH levels, and the second one is easy to miss.
        //
        // `PaymentSecretRedactor` decodes the nested JSON strings it knows about — `data`,
        // `commit_data` — only at the TOP level of the record it is given, because it was written for
        // the order record, where `data` sits at the top. In an items response `data` is one level
        // further down, inside each element of `OrderItems`. Redacting only the envelope therefore
        // strips an item's top-level `cvv` while leaving the one inside its `data` string untouched —
        // and `data` is stored verbatim in a column.
        //
        // So each item is redacted as a record in its own right, which is the shape the redactor
        // expects, and the result is used for BOTH the stored envelope and the mapping below. A test
        // pins this; it was found by that test rather than by reading the redactor.
        $items = array_map(
            static fn(array $item): array => PaymentSecretRedactor::redact($item),
            $items,
        );

        $sanitized = PaymentSecretRedactor::redact($decoded);
        $sanitized['OrderItems'] = $items;

        return [
            'items' => $items,
            'body' => json_encode($sanitized, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Refuses a response that admits to being partial.
     *
     * @param array<array-key, mixed> $decoded
     */
    private function refuseIfIncomplete(array $decoded, int $returned): void
    {
        foreach (self::CONTINUATION_KEYS as $key) {
            if (array_key_exists($key, $decoded) && $decoded[$key] !== null && $decoded[$key] !== false) {
                throw OrderItemDataFailed::malformed(
                    'the response carried a "' . $key . '" marker, so it is only part of the order',
                );
            }
        }

        // A declared total larger than what arrived is the same statement by other means.
        $total = $decoded['total'] ?? null;
        if (is_int($total) && $total > $returned) {
            throw OrderItemDataFailed::malformed(
                'the response declared ' . $total . ' items but carried ' . $returned,
            );
        }
    }
}
