<?php

declare(strict_types=1);

namespace App\Order58\Web\RecentCalls;

use App\Integration\Order58Recording\CallSummary;
use App\Integration\Order58Recording\FixtureAvailability;
use App\Integration\Order58Recording\RecordingChannel;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * `GET /admin/order58/recent-calls/list?store={accountId}` — one store's latest 25 calls, as JSON.
 *
 * What the page's Load Calls button asks for. The store is checked against
 * {@see RecentCallsSettings} before anything is sent, so an edited request cannot list another account.
 *
 * ## Built for being slow without making anything else slow
 *
 *  - **The session is released first.** PHP holds a lock on the session file for the whole request; a
 *    provider that takes seconds to answer would otherwise freeze every other page the same person
 *    opens, because they all wait in `session_start()`. Authentication has already run by now.
 *  - **One outbound request**, with the shared client's connect and response timeouts, and no retry.
 *  - **No audio, and no database.** Every Play, Download and receipt address is built here, so the page
 *    composes no URL of its own; the audio itself is fetched only when a button is pressed. The one
 *    thing kept is which orders were listed, in the session, so their receipts can be opened.
 */
final readonly class ListAction
{
    public function __construct(
        private RecentCallsLoader $loader,
        private ResponseFactoryInterface $responseFactory,
        private UrlGeneratorInterface $urlGenerator,
        private SessionInterface $session,
        private RecentCallsReceipts $receipts,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $accountId = RecentCallsSettings::find($params['store'] ?? null);

        if ($accountId === null) {
            return $this->json(400, ['error' => 'Choose one of the listed stores.']);
        }

        $useFixtures = FixtureAvailability::isRequested($params[FixtureAvailability::QUERY_PARAMETER] ?? null);

        // Released before anything talks to the provider; nothing below reads or writes the session.
        $this->session->close();

        $list = $this->loader->load($accountId, $useFixtures);

        if ($list->problem !== null) {
            // The shared diagnosis's own sentences: written by this application, never the provider's
            // body or the request URL. 502, because the fault is upstream of this server.
            return $this->json(502, [
                'store' => $accountId,
                'error' => $list->problem->headline,
                'detail' => $list->problem->advice,
            ]);
        }

        $rows = [];
        $orderIds = [];

        foreach ($list->calls as $call) {
            $orderId = RecentCallsReceipts::orderIdOf($call->orderId);

            if ($orderId !== null) {
                $orderIds[] = $orderId;
            }

            $rows[] = $this->row($accountId, $call, $orderId, $useFixtures);
        }

        // These orders came from the provider's answer for this store, so their receipts may be opened
        // from this page. The provider has answered already; reopening the session here is brief.
        $this->receipts->remember($accountId, $orderIds);

        return $this->json(200, [
            'store' => $accountId,
            'storeName' => RecentCallsSettings::name($accountId),
            'calls' => $rows,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(int $accountId, CallSummary $call, ?int $orderId, bool $useFixtures): array
    {
        // A recording can be asked for only with a session id the request object accepts and the call's
        // own date, which the provider needs (`time`). A row missing either still lists, without buttons.
        $date = $call->hasValidRecordingId() ? $call->derivedDate() : null;
        $channels = [];

        foreach (RecordingChannel::all() as $channel) {
            if ($date === null) {
                $channels[$channel->value] = null;

                continue;
            }

            $query = [
                'store' => $accountId,
                'session' => $call->callSessionId,
                'date' => $date,
                'channel' => $channel->value,
            ];

            if ($useFixtures) {
                $query[FixtureAvailability::QUERY_PARAMETER] = FixtureAvailability::FIXTURE;
            }

            $channels[$channel->value] = [
                'play' => $this->urlGenerator->generate('order58.recent-calls.audio', [], $query),
                'download' => $this->urlGenerator->generate(
                    'order58.recent-calls.audio',
                    [],
                    $query + ['download' => '1'],
                ),
                'fileName' => $channel->fileNameFor($call->callSessionId),
            ];
        }

        return [
            'orderNo' => $call->orderId,
            'sessionId' => $call->callSessionId,
            'callTime' => $call->callTime,
            'channels' => $channels,
            // Every call with a numeric order id gets a receipt link — whether or not this application has
            // synchronised the order. It opens through ReceiptAction, which accepts only orders listed
            // here for this store.
            'receipt' => $orderId === null
                ? null
                : $this->urlGenerator->generate('order58.recent-calls.receipt', [], [
                    'store' => $accountId,
                    'order' => $orderId,
                ]),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function json(int $status, array $payload): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // A snapshot of a third party's state, holding customer order numbers: never cached.
            ->withHeader('Cache-Control', 'no-store, private');

        $response->getBody()->write(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $response;
    }
}
