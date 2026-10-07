<?php

declare(strict_types=1);

namespace App\Tests\Unit\OrderTesting;

use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Application\DemoOrderDirectory;
use App\OrderTesting\Application\DemoOrderDocument;
use App\OrderTesting\Domain\DemoOrderFileName;
use App\Shared\Order58\PaymentSecretRedactor;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function hash;
use function str_contains;

/**
 * What the importer will and will not accept.
 *
 * All of it runs against real fixture files on disk and none of it touches a database or a network, so
 * these are the rules that can be checked on any machine, including one that has never had a demo
 * order. The gates are the whole security of this feature: the first is which directory may be read,
 * and the second is whether a document admits to being a test.
 */
final class DemoOrderImportRulesTest extends TestCase
{
    private const SOURCE_ORDER = 16758551;

    private function fixtures(string $subdirectory): string
    {
        return dirname(__DIR__, 2) . '/_data/order-testing/' . $subdirectory;
    }

    private function parse(string $directory, string $basename): DemoOrderDocument
    {
        $name = DemoOrderFileName::parse($basename);
        self::assertNotNull($name, $basename . ' should be a parseable demo-order filename');

        return DemoOrderDocument::parse(
            $name,
            (string) file_get_contents($directory . '/' . $basename),
            null,
            new OrderNormalizer(),
        );
    }

    // ------------------------------------------------------------------ the filename is a whitelist

    /**
     * @dataProvider unacceptableNames
     */
    public function testOnlyTheExpectedFilenameShapeIsAccepted(string $basename): void
    {
        self::assertNull(
            DemoOrderFileName::parse($basename),
            $basename . ' must not be opened by the importer',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function unacceptableNames(): iterable
    {
        yield 'no demo id' => ['16758551.json'];
        yield 'not digits' => ['not-an-order.json'];
        yield 'double extension' => ['16758551-16630851.json.bak'];
        // The ones that matter: a name that escapes the directory it is joined to.
        yield 'traversal' => ['../../16758551-16630651.json'];
        yield 'separator' => ['sub/16758551-16630651.json'];
        yield 'leading zero' => ['016758551-16630651.json'];
        yield 'too long' => ['12345678901234567890-16630651.json'];
        yield 'dotfile' => ['.16758551-16630651.json'];
    }

    public function testAnAcceptedNameCarriesBothIds(): void
    {
        $name = DemoOrderFileName::parse('16758551-16630651.json');

        self::assertNotNull($name);
        self::assertSame(self::SOURCE_ORDER, $name->sourceOrderId);
        self::assertSame(16630651, $name->demoOrderId);
    }

    // ------------------------------------------------------------------ the live directory is refused

    /**
     * The invariant this feature is built around: it must never read live customer orders.
     *
     * Checked segment by segment, which is the only comparison that gets both halves right —
     * `demo_mix_orders` is a different segment rather than a longer one.
     */
    public function testTheLiveOrderDirectoryIsRefused(): void
    {
        $status = (new DemoOrderDirectory('/data/orders/mix_orders'))->resolve();

        self::assertFalse($status->isUsable());
        self::assertStringContainsString('LIVE order directory', (string) $status->problem);
    }

    public function testADirectoryInsideTheLiveOneIsAlsoRefused(): void
    {
        $status = (new DemoOrderDirectory('/data/orders/mix_orders/2026/10'))->resolve();

        self::assertFalse($status->isUsable());
        self::assertStringContainsString('LIVE order directory', (string) $status->problem);
    }

    public function testADemoDirectoryIsNotMistakenForTheLiveOne(): void
    {
        $status = (new DemoOrderDirectory($this->fixtures('demo_mix_orders')))->resolve();

        self::assertTrue($status->isUsable(), (string) $status->problem);
    }

    public function testAMissingDirectoryIsReportedRatherThanThrown(): void
    {
        $status = (new DemoOrderDirectory($this->fixtures('no_such_directory')))->resolve();

        self::assertFalse($status->isUsable());
        self::assertStringContainsString('does not exist', (string) $status->problem);
    }

    public function testOnlyDemoOrderFilesAreListed(): void
    {
        $directory = new DemoOrderDirectory($this->fixtures('rejects'));
        $files = $directory->filesIn((string) $directory->resolve()->path);

        $names = [];
        foreach ($files as $file) {
            $names[] = $file->basename;
        }

        self::assertNotContains('not-an-order.json', $names);
        self::assertNotContains('16758551.json', $names);
        self::assertNotContains('16758551-16630851.json.bak', $names);
        self::assertContains('16758551-16630801.json', $names, 'a correctly named file must be listed');
    }

    // ------------------------------------------------------------------ the document's own gates

    /**
     * @dataProvider refusals
     */
    public function testADocumentThatFailsAGateIsRefused(string $basename, string $expected): void
    {
        $document = $this->parse($this->fixtures('rejects'), $basename);

        self::assertNull($document->order, $basename . ' must not produce a row');
        self::assertStringContainsString($expected, (string) $document->refusal);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'malformed JSON' => ['16758551-16630801.json', 'not valid JSON'];
        yield 'a live order' => ['16758551-16630811.json', 'is_test is not 1'];
        yield 'id disagrees with filename' => ['16758551-16630821.json', 'does not match the demo id'];
        yield 'uid names another order' => ['16758551-16630831.json', 'different source order'];
        yield 'no store' => ['16758551-16630841.json', 'account_id'];
    }

    /** A refusal names the gate and never the document, because it is logged and printed. */
    public function testARefusalCarriesNoCustomerData(): void
    {
        $document = $this->parse($this->fixtures('rejects'), '16758551-16630811.json');

        self::assertStringNotContainsString('19178532637', (string) $document->refusal);
        self::assertStringNotContainsString('testa', (string) $document->refusal);
    }

    // ------------------------------------------------------------------ what an accepted document becomes

    public function testAnAcceptedDocumentTakesItsSourceOrderFromTheFilename(): void
    {
        $order = $this->parse($this->fixtures('demo_mix_orders'), '16758551-16630651.json')->order;

        self::assertNotNull($order);
        self::assertSame(self::SOURCE_ORDER, $order->sourceOrderId);
        self::assertSame(16630651, $order->demoOrderId);
        self::assertSame(1731, $order->storeSourceId);
    }

    public function testTheCustomerAndTotalsAreReadOutOfTheNestedDataString(): void
    {
        $order = $this->parse($this->fixtures('demo_mix_orders'), '16758551-16630651.json')->order;

        self::assertNotNull($order);
        // `data` is a JSON-encoded string inside the document, not an object. Reading it needs two
        // decodes, and getting that wrong silently nulls every customer field.
        self::assertSame('testa', $order->customerFirstName);
        self::assertSame('19178532637', $order->customerPhone);
        self::assertSame('162 N Main St', $order->address);
        self::assertSame('Freeport', $order->city);
        // Money stays a five-decimal STRING all the way through.
        self::assertSame('4.28000', $order->totalAmount);
    }

    /**
     * The one that is prohibited rather than merely unwise.
     *
     * A trainee is being taught to enter card details, so the demo directory is if anything more likely
     * to hold them than the live one.
     */
    public function testPaymentSecretsNeverReachTheStoredPayload(): void
    {
        $order = $this->parse($this->fixtures('demo_mix_orders'), '16758551-16630651.json')->order;

        self::assertNotNull($order);

        foreach (PaymentSecretRedactor::PAYMENT_SECRETS as $secret) {
            self::assertStringNotContainsString(
                '"' . $secret . '"',
                $order->rawPayload,
                $secret . ' must never be stored',
            );
        }

        self::assertStringNotContainsString('4111111111111111', $order->rawPayload);
        self::assertStringNotContainsString('4242424242424242', $order->rawPayload);
        // Both copies: the fixture carries them inside `cc` AND beside it, which is what the recursive
        // strip exists for.
        self::assertFalse(str_contains($order->rawPayload, '12/29'));
    }

    /** The address survives redaction by being read before it, and lands in its own columns. */
    public function testTheDeliveryAddressSurvivesRedactionThroughItsColumns(): void
    {
        $order = $this->parse($this->fixtures('demo_mix_orders'), '16758551-16630651.json')->order;

        self::assertNotNull($order);
        self::assertSame('162 N Main St', $order->address);

        // …and the `street1` KEY is genuinely gone from the blob, which is what makes the column
        // necessary. Asserted on the key rather than on the text: `destination_address` is a separate
        // field Order58 composes from the same words, it is not on the secret list, and it is kept.
        // The contract redaction makes is about named fields, never about a string never appearing.
        self::assertStringNotContainsString('"street1"', $order->rawPayload);
    }

    /** Hashing the redacted payload is what lets a row written before redaction be rewritten clean. */
    public function testTheContentHashCoversTheRedactedPayload(): void
    {
        $order = $this->parse($this->fixtures('demo_mix_orders'), '16758551-16630651.json')->order;

        self::assertNotNull($order);
        self::assertSame(hash('sha256', $order->rawPayload), $order->contentHash);
    }

    public function testTheSameDocumentAlwaysHashesTheSame(): void
    {
        $first = $this->parse($this->fixtures('demo_mix_orders'), '16758551-16630651.json')->order;
        $second = $this->parse($this->fixtures('demo_mix_orders'), '16758551-16630651.json')->order;

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->contentHash, $second->contentHash);
    }

    /** Three demo orders, one source order: the shape production already proved. */
    public function testOneSourceOrderParsesIntoThreeDistinctDemoOrders(): void
    {
        $ids = [];

        foreach (['16630651', '16630661', '16630671'] as $demoId) {
            $order = $this->parse(
                $this->fixtures('demo_mix_orders'),
                '16758551-' . $demoId . '.json',
            )->order;

            self::assertNotNull($order);
            self::assertSame(self::SOURCE_ORDER, $order->sourceOrderId);
            $ids[] = $order->demoOrderId;
        }

        self::assertSame([16630651, 16630661, 16630671], $ids);
    }
}
