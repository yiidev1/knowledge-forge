<?php

declare(strict_types=1);

namespace App\Order58\Web\RecentCalls;

use App\Order58\Client\Receipt\ReceiptClientInterface;
use App\Order58\Web\Receipt\Action as ReceiptPage;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Session\SessionInterface;

use function htmlspecialchars;
use function is_scalar;
use function trim;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * `GET /admin/order58/recent-calls/receipt?store={accountId}&order={orderId}` — the receipt of a call
 * the Recent Calls page listed.
 *
 * ## The same receipt, a different proof of ownership
 *
 * The provider's receipt endpoint needs the Order58 token, so a browser can never open it directly; the
 * receipt is always fetched by this server. What differs from Manage Orders is only how the order is
 * shown to belong to the store: here it must have been in the provider's own latest-calls answer for
 * that store, in this session ({@see RecentCallsReceipts}). Once that holds, the request is handed to
 * the **existing** receipt page unchanged — the same provider call, the same image checks, the same
 * page, the same failure wording — with {@see ListedOrder} answering its ownership question.
 */
final readonly class ReceiptAction
{
    public function __construct(
        private RecentCallsReceipts $listed,
        private SessionInterface $session,
        private ReceiptClientInterface $receipts,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $accountId = RecentCallsSettings::find($params['store'] ?? null);
        $orderId = RecentCallsReceipts::orderIdOf($this->text($params['order'] ?? null));

        if ($accountId === null || $orderId === null) {
            return $this->problem(400, 'Invalid receipt request', 'A listed store and a numeric order id are required.');
        }

        if (!$this->listed->allows($accountId, $orderId)) {
            return $this->problem(
                404,
                'This order is not in the loaded calls',
                'Receipts open only for orders in the latest calls loaded for this store. Load the calls '
                . 'again on the Recent Calls page, then open the receipt from its row.',
            );
        }

        // Read what it needed; released before the provider is contacted.
        $this->session->close();

        $page = new ReceiptPage(
            new ListedOrder($accountId, $orderId),
            $this->receipts,
            $this->responseFactory,
            $this->streamFactory,
        );

        return $page($accountId, $orderId);
    }

    /** The same plain page the existing receipt action uses for a refusal. */
    private function problem(int $status, string $title, string $detail): ResponseInterface
    {
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>'
            . '</head><body bgcolor="#14161c" text="#e6e8ee"><div align="center">'
            . '<h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
            . '<p>' . htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '</div></body></html>';

        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'")
            ->withBody($this->streamFactory->createStream($html));
    }

    private function text(mixed $raw): string
    {
        return is_scalar($raw) ? trim((string) $raw) : '';
    }
}
