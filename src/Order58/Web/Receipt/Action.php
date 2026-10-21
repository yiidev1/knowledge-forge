<?php

declare(strict_types=1);

namespace App\Order58\Web\Receipt;

use App\Order58\Client\Receipt\ReceiptClientInterface;
use App\Order58\Client\Receipt\ReceiptDelivery;
use App\Order58\Client\Receipt\ReceiptFailed;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function array_key_exists;
use function base64_encode;
use function htmlspecialchars;
use function str_starts_with;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * `GET /order-testing/store/{sourceId}/order/{sourceOrderId}/receipt` — one order's Order58 receipt.
 *
 * The server-side half of the View Receipt link. The browser never sees the provider's host, its token
 * or its response shape; it opens a URL on this application and gets back an image.
 *
 * ## Three gates, in this order
 *
 * 1. **The route group.** This lives under `RequireOrderTestingAccessMiddleware`, which admits
 *    administrators and operations users and refuses agents outright. There is no per-action role check
 *    because there is no per-action role check anywhere in this application.
 * 2. **The order must be this store's.** `{sourceOrderId}` arrives in a URL an administrator can edit,
 *    and `order58_orders` is unique on `(account_id, source_order_id)` rather than on the order id
 *    alone. Looking it up by the pair is what stops one merchant's receipt being fetched from another
 *    merchant's page by changing a number.
 * 3. **The identifiers are read from the row, never from the request.** What is sent upstream is the
 *    `account_id` and `source_order_id` the database holds, not the digits that came in.
 *
 * ## Why GET, and why no CSRF token
 *
 * It opens in a new tab, it changes nothing here, and it is a link rather than a form — the same shape
 * as every other read endpoint on this page. The upstream call is a POST, but that is our request to
 * them, not the reader's request to us.
 *
 * ## Failures are a page, not a blank tab
 *
 * Every failure renders a small HTML page naming what went wrong in words an administrator can act on.
 * A blank tab or a raw exception would be indistinguishable from "the receipt is empty", and a provider
 * error body is written by somebody else and never forwarded.
 */
final readonly class Action
{
    public function __construct(
        private Order58OrderRepositoryInterface $orders,
        private ReceiptClientInterface $receipts,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $sourceId,
        #[RouteArgument]
        int $sourceOrderId,
    ): ResponseInterface {
        /*
         * Gate two: is this order really this store's?
         *
         * `hashesFor()` is the store-scoped batched lookup the sync already uses, and it returns a key
         * for every row it found under this account — so asking it about one id is an existence check
         * bounded by exactly the pair that `ux_order58_orders_account_order` is unique on. The hash
         * itself is not wanted; a dedicated `exists()` on the interface would be clearer prose and
         * would also break four existing test doubles for nothing.
         *
         * An order under a different account simply is not here — the same answer as one that does not
         * exist at all, which is also the right answer to give.
         */
        $known = $this->orders->hashesFor($sourceId, [$sourceOrderId]);

        if (!array_key_exists($sourceOrderId, $known)) {
            return $this->problem(
                404,
                'That order is not on this store',
                'This store has no mirrored Order58 order with that number, so there is no receipt to '
                . 'fetch. If the order is new, sync this store\'s orders and try again.',
            );
        }

        try {
            // Gate three: the pair that goes upstream is the pair the database just confirmed, both
            // already cast to int by the route's own `\d+` constraint and the hydrator.
            $receipt = $this->receipts->fetch($sourceId, $sourceOrderId);
        } catch (ReceiptFailed $e) {
            // The message is ours, written for an administrator; nothing of the provider's body reaches
            // it. 502 rather than 500: the fault is upstream, and the distinction matters to whoever
            // reads the access log.
            return $this->problem(502, 'The receipt could not be loaded', $e->getMessage());
        }

        // An image gets a page to sit in the middle of; a PDF already has a viewer of its own.
        if (str_starts_with($receipt->contentType, 'image/')) {
            return $this->centred($receipt, $sourceOrderId);
        }

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', $receipt->contentType)
            ->withHeader('Content-Length', (string) $receipt->sizeBytes())
            // `inline`, so the tab shows it rather than downloading it — with a filename built from our
            // own order id for the reader who then saves it.
            ->withHeader(
                'Content-Disposition',
                'inline; filename="' . $receipt->downloadName($sourceOrderId) . '"',
            )
            // Somebody's order, with their name and address on it. It may be held by the browser that
            // asked for it and by nothing in between, and it must be re-fetched rather than re-shown.
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            // The type was checked against an allow-list before we got here, so the browser must take
            // it literally rather than sniffing bytes from an upstream we do not control.
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Belt and braces on an image route: nothing here may frame, script or be framed.
            ->withHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; sandbox")
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withBody($this->streamFactory->createStream($receipt->bytes));
    }

    /**
     * The receipt, centred on a page of its own.
     *
     * A browser handed a bare image paints it hard against the top-left corner, which is what this
     * tab used to show: a receipt in the corner and a wide empty gutter beside it.
     *
     * ## Why the image is embedded rather than linked
     *
     * A second route serving the bytes would mean a second request, and a second request means a
     * second call to the provider — this is deliberately uncached, so the same receipt would be
     * fetched twice for one viewing. Embedding it as a `data:` URI keeps one view to one upstream
     * call. Base64 costs about a third in size, which on a ~96 KB receipt is noise.
     *
     * That also keeps the page sealed: `default-src 'none'` with `img-src data:` means it cannot
     * reach the network at all — not to our origin, not to the provider's. The only markup here is
     * this string, built from an allow-listed media type and base64; nothing of the provider's body
     * is ever interpreted.
     *
     * Aligned to the top rather than the middle of the viewport, because a long receipt centred
     * vertically has its head and feet cut off with no way to scroll to them.
     */
    private function centred(ReceiptDelivery $receipt, int $sourceOrderId): ResponseInterface
    {
        $name = htmlspecialchars($receipt->downloadName($sourceOrderId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $src = 'data:' . $receipt->contentType . ';base64,' . base64_encode($receipt->bytes);

        /*
         * Centred with presentational HTML, not CSS, and that is not a style choice.
         *
         * This application sends `style-src 'self'` with no `unsafe-inline`, from its own middleware
         * **and again from nginx** — and a browser enforces the intersection of the two, so PHP cannot
         * relax it on its own. An inline `<style>` block here is simply refused: the first version of
         * this page had one, and what the reader got was an unstyled document with the receipt against
         * the top-left corner and a default 8px margin. Exactly the bug this method exists to fix.
         *
         * `align` and `bgcolor` are HTML attributes, not CSS. No CSP directive governs them, every
         * browser still honours them, and they need no stylesheet, no asset publishing and no change to
         * the server configuration. Deprecated, and the right tool for a sealed standalone page.
         *
         * `vspace`/`hspace` give the receipt a little air without a style rule.
         */
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Receipt ' . $sourceOrderId . '</title>'
            . '</head><body bgcolor="#14161c"><div align="center">'
            . '<img src="' . $src . '" alt="Receipt for order ' . $sourceOrderId . '" title="' . $name . '"'
            . ' vspace="24" hspace="24">'
            . '</div></body></html>';

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            // Somebody's order, with their name and address on it, now inlined into the page — so the
            // page itself must not be stored any more than the image was.
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Sealed: no script, no network. The application and nginx each send their own policy and
            // the browser takes the intersection, so this is a floor rather than the final word — which
            // is exactly why the page is styled with HTML attributes and needs no inline CSS to work.
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'none'; img-src data:; frame-ancestors 'none'",
            )
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withBody($this->streamFactory->createStream($html));
    }

    /**
     * A readable failure, as a self-contained page.
     *
     * Deliberately not the application layout: this tab is opened straight onto this URL, it has no
     * navigation of its own to inherit, and a layout would pull in scripts and styles an error page has
     * no use for. The message is escaped because it is text, even though every one of them is ours.
     */
    private function problem(int $status, string $title, string $detail): ResponseInterface
    {
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>'
            // Presentational attributes for the same reason as the receipt page above: `style-src
            // 'self'` is sent twice and refuses an inline `<style>`, so a styled version of this page
            // was being shown as unstyled serif text on white. This one is plain by intent instead.
            . '</head><body bgcolor="#14161c" text="#e6e8ee"><div align="center">'
            . '<h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
            . '<p>' . htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '</div></body></html>';

        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Stated for the record even though the application and nginx each send their own and the
            // browser enforces the intersection. It documents that this page needs nothing at all.
            ->withHeader('Content-Security-Policy', "default-src 'none'")
            ->withBody($this->streamFactory->createStream($html));
    }
}
