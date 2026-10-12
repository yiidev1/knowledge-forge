<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Items;

use App\Order58\Application\Items\ItemSyncOutcome;
use App\Order58\Application\Items\OrderItemSyncInterface;
use App\Order58\Domain\Orders\Order58Order;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use App\Order58\Domain\Orders\OrderListQuery;
use App\Order58\Web\OrderItems\SyncAction;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Codeception\Test\Unit;
use DateTimeImmutable;
use HttpSoft\Message\ResponseFactory;
use HttpSoft\Message\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Stringable;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\Flash\FlashInterface;

use function array_key_last;
use function is_array;

/**
 * The manual "Sync Order Items" button, from both pages. **No test here reaches a network.**
 *
 * The provider is an interface and the repository is an in-memory double, so "no live call" is
 * structural rather than a promise — and several tests assert the call count is *zero*, which is the
 * part that matters for an endpoint that spends somebody's API quota.
 *
 * ## What is really being guarded
 *
 * The authorization boundary. Both `account_id` and `source_order_id` arrive in the request body, so
 * neither can be trusted: without proving the order is already mirrored **for that account**, this
 * endpoint would fetch any order id anybody typed, on any account.
 *
 * And the return contract: a sync must put the operator back where they were — the same filtered page
 * of the listing, or the same comparison — never on an unfiltered page one.
 */
final class ManualSyncActionTest extends Unit
{
    private const ACCOUNT = 1731;
    private const ORDER = 16800401;
    private const DEMO = 16630821;

    // ------------------------------------------------------------------ authorization

    /** An order this account does not have must never reach the provider. */
    public function testAnOrderThisAccountDoesNotOwnIsRefusedWithoutAnyRequest(): void
    {
        $h = $this->harness(owns: false);

        $h->action->__invoke($this->request(['account_id' => '1731', 'source_order_id' => '999999999']));

        $this->assertSame(0, $h->sync->calls, 'An unowned order must not be fetched.');
        $this->assertSame('error', $h->flash->last['level'] ?? null);
    }

    /**
     * The refusal says the same thing for "not yours" and "not a number".
     *
     * An operator who may not see an order learns nothing about whether it exists.
     */
    public function testTheRefusalDoesNotDistinguishUnknownFromForbidden(): void
    {
        $unowned = $this->harness(owns: false);
        $unowned->action->__invoke($this->request(['account_id' => '1731', 'source_order_id' => '999999999']));

        $malformed = $this->harness();
        $malformed->action->__invoke($this->request(['account_id' => '1731', 'source_order_id' => 'not-a-number']));

        $this->assertNotNull($unowned->flash->last);
        $this->assertSame($unowned->flash->last['message'], $malformed->flash->last['message'] ?? null);
    }

    /**
     * @dataProvider badIdentifiers
     */
    public function testAMalformedIdentifierIsRefused(string $account, string $order): void
    {
        $h = $this->harness();

        $h->action->__invoke($this->request(['account_id' => $account, 'source_order_id' => $order]));

        $this->assertSame(0, $h->sync->calls);
        $this->assertSame('error', $h->flash->last['level'] ?? null);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badIdentifiers(): iterable
    {
        yield 'empty order' => ['1731', ''];
        yield 'zero order' => ['1731', '0'];
        yield 'negative' => ['1731', '-5'];
        yield 'trailing letters' => ['1731', '16800401abc'];
        yield 'leading zero' => ['1731', '016800401'];
        yield 'empty account' => ['', '16800401'];
        yield 'overlong' => ['1731', '12345678901234567890'];
    }

    /** The ownership query is scoped to BOTH the account and the order, not to the order alone. */
    public function testOwnershipIsCheckedAgainstTheAccountAndTheOrderTogether(): void
    {
        $h = $this->harness();

        $h->action->__invoke($this->request($this->validBody()));

        $this->assertSame([[self::ACCOUNT, self::ORDER]], $h->orders->asked);
    }

    // ------------------------------------------------------------------ outcomes

    public function testASuccessfulSyncReportsTheItemCount(): void
    {
        $h = $this->harness(ItemSyncOutcome::synced(3));

        $h->action->__invoke($this->request($this->validBody()));

        $this->assertSame('success', $h->flash->last['level'] ?? null);
        $this->assertStringContainsString('3 items', (string) ($h->flash->last['message'] ?? ''));
    }

    /** A verified empty order is a success, and must not read as a failure that happened quietly. */
    public function testAnEmptyOrderIsReportedAsSuccess(): void
    {
        $h = $this->harness(ItemSyncOutcome::empty());

        $h->action->__invoke($this->request($this->validBody()));

        $this->assertSame('success', $h->flash->last['level'] ?? null);
        $this->assertStringContainsString('No items returned', (string) ($h->flash->last['message'] ?? ''));
    }

    /** A second click while the worker holds it: told, not queued, and no second request. */
    public function testAnOrderAlreadyInProgressIsReportedWithoutAFailure(): void
    {
        $h = $this->harness(ItemSyncOutcome::busy());

        $h->action->__invoke($this->request($this->validBody()));

        $this->assertSame('warning', $h->flash->last['level'] ?? null, 'Busy is neither success nor error.');
        $this->assertStringContainsString('already in progress', (string) ($h->flash->last['message'] ?? ''));
    }

    /** A failure must promise the operator that nothing was destroyed. */
    public function testAFailureSaysThePreviousItemsWereKept(): void
    {
        $h = $this->harness(ItemSyncOutcome::failed('The Order Items API is unavailable.'));

        $h->action->__invoke($this->request($this->validBody()));

        $this->assertSame('error', $h->flash->last['level'] ?? null);
        $this->assertStringContainsString(
            'Previously saved items were preserved',
            (string) ($h->flash->last['message'] ?? ''),
        );
    }

    /**
     * Off is reported as off, not as a failure.
     *
     * A disabled feature is a fact about the installation. Reporting it as an error would tell the
     * operator that the provider or their order is broken, and send them looking in the wrong place.
     */
    public function testAnUnavailableInstallationIsReportedAsSuchAndNotAsAFailure(): void
    {
        $message = 'Original Order Items synchronization is not enabled or configured.';
        $h = $this->harness(ItemSyncOutcome::unavailable($message));

        $h->action->__invoke($this->request($this->validBody()));

        $this->assertSame('warning', $h->flash->last['level'] ?? null);
        $this->assertSame($message, $h->flash->last['message'] ?? null);
        $this->assertStringNotContainsString(
            'preserved',
            (string) ($h->flash->last['message'] ?? ''),
            'Nothing needed preserving: nothing was attempted.',
        );
    }

    public function testAnUnchangedResponseIsReportedAsUpToDate(): void
    {
        $h = $this->harness(ItemSyncOutcome::unchanged(2));

        $h->action->__invoke($this->request($this->validBody()));

        $this->assertSame('info', $h->flash->last['level'] ?? null);
        $this->assertStringContainsString('already up to date', (string) ($h->flash->last['message'] ?? ''));
    }

    /** Singular when there is one of them; the count is read by a human. */
    public function testASingleItemIsReportedInTheSingular(): void
    {
        $h = $this->harness(ItemSyncOutcome::synced(1));

        $h->action->__invoke($this->request($this->validBody()));

        $this->assertStringContainsString('1 item.', (string) ($h->flash->last['message'] ?? ''));
    }

    /** The claim is widened for a button press, which is what lets it jump the worker's queue. */
    public function testTheManualFlagIsPassedToTheService(): void
    {
        $h = $this->harness();

        $h->action->__invoke($this->request($this->validBody()));

        $this->assertSame([[self::ACCOUNT, self::ORDER, true]], $h->sync->seen);
    }

    // ------------------------------------------------------------------ where the operator lands

    /** Every outcome redirects: a POST must never render, or a refresh replays the sync. */
    public function testTheResponseIsAlwaysASeeOtherRedirect(): void
    {
        $h = $this->harness();

        $response = $h->action->__invoke($this->request($this->validBody()));

        $this->assertSame(303, $response->getStatusCode());
        $this->assertNotSame('', $response->getHeaderLine('Location'));
    }

    public function testTheListingKeepsItsFiltersAndPage(): void
    {
        $h = $this->harness();

        $h->action->__invoke($this->request($this->validBody() + [
            'return' => 'order58.orders',
            'order_id' => '16800401',
            'status' => 'completed',
            'list_from' => '2026-10-01',
            'list_to' => '2026-10-08',
            'page' => '3',
        ]));

        $this->assertSame('order58.orders', $h->urls->lastRoute);
        // Every key the listing itself reads. A filter missing here is a filter silently reset.
        $this->assertSame([
            'account_id' => '1731',
            'order_id' => '16800401',
            'status' => 'completed',
            'list_from' => '2026-10-01',
            'list_to' => '2026-10-08',
            'page' => '3',
        ], $h->urls->lastArguments);
    }

    /** An absent filter is left out rather than sent as an empty one the listing would have to ignore. */
    public function testAbsentFiltersAreNotCarriedBackAsEmptyStrings(): void
    {
        $h = $this->harness();

        $h->action->__invoke($this->request($this->validBody() + ['list_from' => '', 'page' => '']));

        $this->assertSame(['account_id' => '1731'], $h->urls->lastArguments);
    }

    public function testTheComparisonPageReturnsToTheSameComparison(): void
    {
        $h = $this->harness();

        $h->action->__invoke($this->request($this->validBody() + [
            'return' => 'order-testing.demo-compare',
            'demo_order_id' => (string) self::DEMO,
        ]));

        $this->assertSame('order-testing.demo-compare', $h->urls->lastRoute);
        $this->assertSame([
            'sourceId' => self::ACCOUNT,
            'sourceOrderId' => self::ORDER,
            'demoOrderId' => self::DEMO,
        ], $h->urls->lastArguments);
    }

    /** An incomplete triple cannot rebuild that URL, so it falls back rather than throwing. */
    public function testAComparisonReturnWithoutADemoIdFallsBackToTheListing(): void
    {
        $h = $this->harness();

        $h->action->__invoke($this->request($this->validBody() + ['return' => 'order-testing.demo-compare']));

        $this->assertSame('order58.orders', $h->urls->lastRoute);
    }

    /** Allow-listed, so the return field can never become an open redirect. */
    public function testAnArbitraryReturnRouteIsIgnored(): void
    {
        $h = $this->harness();

        $h->action->__invoke($this->request($this->validBody() + ['return' => 'https://evil.example.com/']));

        $this->assertSame('order58.orders', $h->urls->lastRoute);
    }

    /** A refused sync still lands on the page it came from, filters intact. */
    public function testARefusalAlsoReturnsToTheComparisonPage(): void
    {
        $h = $this->harness(owns: false);

        $h->action->__invoke($this->request([
            'account_id' => (string) self::ACCOUNT,
            'source_order_id' => '999999999',
            'demo_order_id' => (string) self::DEMO,
            'return' => 'order-testing.demo-compare',
        ]));

        $this->assertSame('order-testing.demo-compare', $h->urls->lastRoute);
    }

    // ------------------------------------------------------------------ harness

    /** @return array<string, string> */
    private function validBody(): array
    {
        return ['account_id' => (string) self::ACCOUNT, 'source_order_id' => (string) self::ORDER];
    }

    /** @param array<string, string> $body */
    private function request(array $body): ServerRequestInterface
    {
        return new ServerRequest(parsedBody: $body, method: 'POST', uri: '/admin/order58/order-items/sync');
    }

    private function harness(ItemSyncOutcome|bool $outcome = true, bool $owns = true): ManualSyncHarness
    {
        $sync = new SyncSpy($outcome instanceof ItemSyncOutcome ? $outcome : ItemSyncOutcome::synced(1));
        $flash = new FlashSpy();
        $urls = new RecordingUrlGenerator();
        $orders = new OrderOwnershipStub($owns);

        return new ManualSyncHarness(
            new SyncAction($sync, $orders, new FlashMessages($flash), new Redirect(new ResponseFactory(), $urls)),
            $sync,
            $flash,
            $urls,
            $orders,
        );
    }
}

/** The assembled action and the doubles a test needs to look at afterwards. */
final readonly class ManualSyncHarness
{
    public function __construct(
        public SyncAction $action,
        public SyncSpy $sync,
        public FlashSpy $flash,
        public RecordingUrlGenerator $urls,
        public OrderOwnershipStub $orders,
    ) {}
}

/** Records what the action asked of the service, and answers with a canned outcome. */
final class SyncSpy implements OrderItemSyncInterface
{
    public int $calls = 0;
    /** @var list<array{int, int, bool}> */
    public array $seen = [];

    public function __construct(private readonly ItemSyncOutcome $outcome) {}

    public function syncOrder(int $accountId, int $sourceOrderId, bool $manual = false): ItemSyncOutcome
    {
        ++$this->calls;
        $this->seen[] = [$accountId, $sourceOrderId, $manual];

        return $this->outcome;
    }
}

/** Answers the ownership question, and remembers what it was asked. */
final class OrderOwnershipStub implements Order58OrderRepositoryInterface
{
    /** @var list<array{int|null, int|null}> */
    public array $asked = [];

    public function __construct(private readonly bool $owns) {}

    public function page(OrderListQuery $query): array
    {
        $this->asked[] = [$query->accountId, $query->sourceOrderId];

        return ['rows' => [], 'total' => $this->owns ? 1 : 0];
    }

    public function hashesFor(int $accountId, array $sourceOrderIds): array
    {
        return [];
    }

    public function upsert(Order58Order $order, DateTimeImmutable $now): bool
    {
        return true;
    }
}

/** Keeps the last flash so a test can assert both its level and its wording. */
final class FlashSpy implements FlashInterface
{
    /** @var array{level: string, message: string}|null */
    public ?array $last = null;

    public function get(string $key, mixed $defaultValue = null): mixed
    {
        return $defaultValue;
    }

    public function getAll(): array
    {
        return [];
    }

    public function set(string $key, mixed $value = true, bool $removeAfterAccess = true): void
    {
        if (!is_array($value)) {
            return;
        }

        /** @var mixed $entry */
        $entry = $value[array_key_last($value)] ?? null;

        if (is_array($entry) && isset($entry['level'], $entry['message'])) {
            $this->last = ['level' => (string) $entry['level'], 'message' => (string) $entry['message']];
        }
    }

    public function remove(string $key): void {}

    public function removeAll(): void {}

    public function has(string $key): bool
    {
        return false;
    }

    /** The one the production wrapper actually calls. */
    public function add(string $key, mixed $value = true, bool $removeAfterAccess = true): void
    {
        $this->set($key, [$value], $removeAfterAccess);
    }
}

/**
 * Captures the redirect target instead of resolving a route.
 *
 * {@see Redirect} is `final`, and deliberately so — the seam a test needs is the URL generator it was
 * given, not the class itself.
 */
final class RecordingUrlGenerator implements UrlGeneratorInterface
{
    public ?string $lastRoute = null;
    /** @var array<array-key, mixed> */
    public array $lastArguments = [];

    public function generate(
        string $name,
        array $arguments = [],
        array $queryParameters = [],
        ?string $hash = null,
    ): string {
        $this->lastRoute = $name;
        $this->lastArguments = $arguments;

        return '/' . $name;
    }

    public function generateAbsolute(
        string $name,
        array $arguments = [],
        array $queryParameters = [],
        ?string $hash = null,
        ?string $scheme = null,
        ?string $host = null,
    ): string {
        return $this->generate($name, $arguments, $queryParameters, $hash);
    }

    public function generateFromCurrent(
        array $replacedArguments,
        array $queryParameters = [],
        ?string $hash = null,
        ?string $fallbackRouteName = null,
    ): string {
        return '/';
    }

    public function getUriPrefix(): string
    {
        return '';
    }

    public function setUriPrefix(string $name): void {}

    public function setDefaultArgument(string $name, bool|float|int|string|Stringable|null $value): void {}
}
