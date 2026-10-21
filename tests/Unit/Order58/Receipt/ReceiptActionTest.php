<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Receipt;

use App\Order58\Client\Receipt\ReceiptClientInterface;
use App\Order58\Client\Receipt\ReceiptDelivery;
use App\Order58\Client\Receipt\ReceiptFailed;
use App\Order58\Domain\Orders\Order58Order;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use App\Order58\Domain\Orders\OrderListQuery;
use App\Order58\Web\Receipt\Action;
use Codeception\Test\Unit;
use DateTimeImmutable;
use GuzzleHttp\Psr7\HttpFactory;

use function base64_encode;
use function str_contains;

/**
 * The receipt endpoint: who it serves, and what it hands them.
 *
 * The client is a double in every case, so nothing here reaches the provider. What is being guarded
 * is the gate in front of it and the shape of what comes out — both of which were defects once:
 *
 * - the store gate is **load-bearing**, because the provider was measured answering a receipt request
 *   for one account carrying another account's order id. Nothing upstream checks ownership.
 * - a bare image is painted hard against the top-left corner by every browser, which is what this tab
 *   used to show.
 */
final class ReceiptActionTest extends Unit
{
    private const STORE = 1731;
    private const ORDER = 16758551;

    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR-pretend-pixels";

    // ------------------------------------------------------------------ the gate

    /**
     * The order must be this store's, and the check happens before any client is touched.
     *
     * Asserted by giving the action a client that throws if called at all: "it answered 404" and "it
     * never asked the provider" are two different claims, and only the second one is the security
     * property.
     */
    public function testAnOrderThatIsNotThisStoresIs404AndNeverReachesTheProvider(): void
    {
        $client = new class implements ReceiptClientInterface {
            public bool $called = false;

            public function fetch(int $accountId, int $sourceOrderId): ReceiptDelivery
            {
                $this->called = true;

                throw ReceiptFailed::notAvailable();
            }
        };

        $response = $this->action($client, known: false)(self::STORE, self::ORDER);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($client->called, 'The provider must not be called for a foreign order.');
        $this->assertStringContainsString('not on this store', (string) $response->getBody());
    }

    /** The pair sent upstream is the pair the database confirmed, not anything else. */
    public function testTheIdentifiersSentUpstreamAreTheRouteArgumentsAsConfirmed(): void
    {
        $client = new class implements ReceiptClientInterface {
            public int $account = 0;
            public int $order = 0;

            public function fetch(int $accountId, int $sourceOrderId): ReceiptDelivery
            {
                $this->account = $accountId;
                $this->order = $sourceOrderId;

                return new ReceiptDelivery(ReceiptActionTest::png(), 'image/png');
            }
        };

        $this->action($client)(self::STORE, self::ORDER);

        $this->assertSame(self::STORE, $client->account);
        $this->assertSame(self::ORDER, $client->order);
    }

    // ------------------------------------------------------------------ what comes out

    /**
     * An image is centred on a page of its own rather than served bare.
     *
     * Asserted on the markup, not on CSS, because CSS is exactly what does not work here: this
     * application sends `style-src 'self'` with no `unsafe-inline` from its middleware **and again
     * from nginx**, so an inline `<style>` block is refused and the page renders unstyled with the
     * receipt in the top-left corner. That was the first version of this feature. `align` and
     * `bgcolor` are HTML attributes that no CSP directive governs, which is why they are used — and
     * why a future edit that "modernises" them into a stylesheet must not pass this test.
     */
    public function testAnImageIsWrappedInACentredPage(): void
    {
        $response = $this->action($this->serving(self::PNG, 'image/png'))(self::STORE, self::ORDER);
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<div align="center">', $html);
        $this->assertStringContainsString('bgcolor="#14161c"', $html);
        // No inline stylesheet, because the policy would refuse it and the page would be unstyled.
        $this->assertStringNotContainsString('<style', $html);
    }

    /** The bytes are embedded, so one viewing costs one upstream call rather than two. */
    public function testTheImageIsEmbeddedRatherThanLinkedBack(): void
    {
        $html = (string) $this->action($this->serving(self::PNG, 'image/png'))(self::STORE, self::ORDER)
            ->getBody();

        $this->assertStringContainsString('data:image/png;base64,' . base64_encode(self::PNG), $html);
        // No second route to call: nothing in the page points at this application at all.
        $this->assertFalse(str_contains($html, 'src="/'), 'The page must not fetch anything.');
    }

    /** Sealed: no script, and no way to reach the network from that page. */
    public function testTheWrapperPageCanReachNothing(): void
    {
        $response = $this->action($this->serving(self::PNG, 'image/png'))(self::STORE, self::ORDER);
        $csp = $response->getHeaderLine('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString('img-src data:', $csp);
        $this->assertStringNotContainsString('script', $csp);
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /** A PDF already has a viewer of its own, so it is streamed rather than wrapped. */
    public function testAPdfIsStreamedNotWrapped(): void
    {
        $response = $this->action($this->serving('%PDF-1.4', 'application/pdf'))(self::STORE, self::ORDER);

        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('receipt-16758551.pdf', $response->getHeaderLine('Content-Disposition'));
    }

    /** A failure is a page with words on it, never a blank tab, and never the provider's own words. */
    public function testAFailureIsAReadablePageAndCarriesNothingOfTheProviders(): void
    {
        $client = new class implements ReceiptClientInterface {
            public function fetch(int $accountId, int $sourceOrderId): ReceiptDelivery
            {
                throw ReceiptFailed::refused(401);
            }
        };

        $response = $this->action($client)(self::STORE, self::ORDER);

        // 502, because the fault is upstream of us and the access log should say so.
        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringContainsString('could not be loaded', (string) $response->getBody());
        $this->assertStringNotContainsString('Bearer', (string) $response->getBody());
    }

    public static function png(): string
    {
        return self::PNG;
    }

    private function serving(string $bytes, string $type): ReceiptClientInterface
    {
        return new class ($bytes, $type) implements ReceiptClientInterface {
            public function __construct(private string $bytes, private string $type) {}

            public function fetch(int $accountId, int $sourceOrderId): ReceiptDelivery
            {
                return new ReceiptDelivery($this->bytes, $this->type);
            }
        };
    }

    /** @param bool $known whether the mirrored order exists under the account being asked about */
    private function action(ReceiptClientInterface $client, bool $known = true): Action
    {
        $psr7 = new HttpFactory();

        $orders = new class ($known) implements Order58OrderRepositoryInterface {
            public function __construct(private bool $known) {}

            public function hashesFor(int $accountId, array $sourceOrderIds): array
            {
                return $this->known ? [$sourceOrderIds[0] => 'hash'] : [];
            }

            public function upsert(Order58Order $order, DateTimeImmutable $now): bool
            {
                return true;
            }

            public function page(OrderListQuery $query): array
            {
                return ['rows' => [], 'total' => 0];
            }
        };

        return new Action($orders, $client, $psr7, $psr7);
    }
}
