<?php

declare(strict_types=1);

namespace App\Order58\Web\OrderApiViewer;

use App\Order58\Client\CaseOrders\CaseOrderClientInterface;
use App\Order58\Client\CaseOrders\CaseOrderFetchFailed;
use Throwable;

use function count;
use function is_array;

/**
 * One request to the case-order endpoint, reduced to the six fields the table shows.
 *
 * ## It reuses the synchronisation's HTTP client, and deliberately nothing else
 *
 * {@see CaseOrderClientInterface} already knows this endpoint's URL, which of the two auth styles this
 * deployment is configured for, and how to redact payment secrets before anything downstream sees the
 * body. Writing a second client here would mean a second place to configure the same credential and a
 * second redactor to keep in step with the first — and the one that drifted would be this one, because
 * it is the one nobody syncs with.
 *
 * What it does **not** touch is the synchronisation around that client: no run is claimed, no row is
 * written, no payload is staged, nothing is imported. The client is pure HTTP; the rationing,
 * leasing and importing live in `CaseOrderSyncService`, and none of it is reachable from here.
 *
 * ## The allowance is real, and this page spends from it
 *
 * The provider rations this endpoint — originally one request a day, since relaxed to several, with the
 * active-run index enforcing one at a time. **Every page load and every Refresh is one request.** That
 * is the minimum a live viewer can cost and is why nothing on this page polls, auto-refreshes, or
 * re-fetches to play audio: the recording address is derived once, on the server, from the payload
 * already in hand.
 *
 * ## Nothing is kept
 *
 * The response is mapped to rows and dropped. No cache, no file, no database row, no log of the body.
 */
final readonly class OrderFeed
{
    public function __construct(private CaseOrderClientInterface $client) {}

    public function load(): FeedResult
    {
        try {
            $response = $this->client->fetch();
        } catch (CaseOrderFetchFailed $e) {
            // The client's own refusal — a non-2xx, a body that was not JSON, a transport failure. Its
            // message is written for an operator, so it is passed through rather than reworded.
            return FeedResult::failed($e->getMessage());
        } catch (Throwable $e) {
            // Anything else is a fault in this application rather than in the provider. The class name
            // is enough to find it; the message may carry detail that does not belong on a page.
            return FeedResult::failed('The order API could not be reached (' . $e::class . ').');
        }

        $rows = [];

        foreach ($response->orders as $order) {
            if (is_array($order)) {
                $rows[] = OrderRow::fromProviderRecord($order);
            }
        }

        return FeedResult::loaded($rows, $response->httpStatus, $response->responseBytes, count($response->orders));
    }
}
