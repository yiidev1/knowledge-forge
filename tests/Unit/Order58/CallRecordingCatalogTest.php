<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\CallRecordings\CallRecordingCatalog;
use App\Order58\Application\CallRecordings\CallRecordingReport;
use App\Order58\Domain\CallRecordings\ListedCall;
use App\Order58\Domain\CallRecordings\OrderCalls;
use App\Tests\Support\Fake\Order58\InMemoryCallRecordingRepository;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Psr\Log\NullLogger;

/**
 * The rules that keep stored recording links in step with the provider's listing.
 */
final class CallRecordingCatalogTest extends Unit
{
    private const STORE = 987655961;
    private const ORDER = 15594361;
    private const SESSION = '21403649';

    private InMemoryCallRecordingRepository $repository;
    private CallRecordingCatalog $catalog;
    private DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->repository = (new InMemoryCallRecordingRepository())->withOrder(self::STORE, self::ORDER);
        $this->catalog = new CallRecordingCatalog($this->repository, new NullLogger());
        $this->now = new DateTimeImmutable('2026-10-27 12:00:00');
    }

    public function testAFirstListingStoresTheSessionAndItsLink(): void
    {
        $report = $this->record([self::url(1)]);

        self::assertSame(1, $report->sessionsAdded);
        self::assertSame(1, $report->recordingsAdded);
        self::assertSame([self::url(1)], $this->repository->urlsOf(self::STORE, self::ORDER));
    }

    /** **Idempotency**: the same response again changes nothing and runs no write at all. */
    public function testTheSameListingTwiceWritesNothing(): void
    {
        $this->record([self::url(1)]);
        $writes = $this->repository->applyCalls;

        $report = $this->record([self::url(1)]);

        self::assertSame($writes, $this->repository->applyCalls, 'no write for an unchanged listing');
        self::assertSame(1, $report->ordersUnchanged);
        self::assertSame(0, $report->recordingsAdded);
        self::assertCount(1, $this->repository->recordings);
    }

    /** One recording, then two: the new one is added, the first is kept as it was. */
    public function testASecondLinkIsAddedBesideTheFirst(): void
    {
        $this->record([self::url(1)]);
        $report = $this->record([self::url(1), self::url(2)]);

        self::assertSame(0, $report->sessionsAdded);
        self::assertSame(1, $report->recordingsAdded);
        self::assertSame([self::url(1), self::url(2)], $this->repository->urlsOf(self::STORE, self::ORDER));
    }

    /** The provider replaced the link: the old row is marked replaced, never deleted. */
    public function testAReplacedLinkIsDelistedNotDeleted(): void
    {
        $this->record([self::url(1)]);
        $report = $this->record([self::url(2)]);

        self::assertSame(1, $report->recordingsReplaced);
        self::assertSame([self::url(2)], $this->repository->urlsOf(self::STORE, self::ORDER));
        self::assertCount(2, $this->repository->recordings, 'the replaced row is still there');
    }

    public function testALinkThatComesBackIsCurrentAgain(): void
    {
        $this->record([self::url(1)]);
        $this->record([self::url(2)]);
        $report = $this->record([self::url(1), self::url(2)]);

        self::assertSame(1, $report->recordingsRelisted);
        self::assertSame(0, $report->recordingsAdded);
        self::assertSame([self::url(1), self::url(2)], $this->repository->urlsOf(self::STORE, self::ORDER));
    }

    public function testAReorderedListMovesPositionsInPlace(): void
    {
        $this->record([self::url(1), self::url(2)]);
        $this->record([self::url(2), self::url(1)]);

        self::assertSame([self::url(2), self::url(1)], $this->repository->urlsOf(self::STORE, self::ORDER));
        self::assertCount(2, $this->repository->recordings);
    }

    /**
     * **Silence removes nothing.** No calls, an empty list, or a response about another session say
     * nothing about the stored links, so all of them stay current.
     */
    public function testSilenceLeavesEveryStoredLinkAsItWas(): void
    {
        $this->record([self::url(1)]);

        $this->catalog->record([new OrderCalls(self::STORE, self::ORDER, [])], $this->now);
        $this->record([]);
        $this->catalog->record([new OrderCalls(self::STORE, self::ORDER, [
            new ListedCall('99999999', [self::url(7, '99999999')]),
        ])], $this->now);

        self::assertSame(
            [self::url(1), self::url(7, '99999999')],
            $this->repository->urlsOf(self::STORE, self::ORDER),
            'the first session untouched; the second simply added beside it',
        );
    }

    public function testSeveralSessionsOnOneOrderAreEachStored(): void
    {
        $report = $this->catalog->record([new OrderCalls(self::STORE, self::ORDER, [
            new ListedCall(self::SESSION, [self::url(1), self::url(2)]),
            new ListedCall('21403650', [self::url(3, '21403650')]),
        ])], $this->now);

        self::assertSame(2, $report->sessionsAdded);
        self::assertSame(3, $report->recordingsAdded);
        self::assertCount(2, $this->repository->sessions);
    }

    /** An order the import could not write is skipped, not allowed to fail on the foreign key. */
    public function testAnOrderThatIsNotStoredIsSkipped(): void
    {
        $report = $this->catalog->record([new OrderCalls(self::STORE, 1, [
            new ListedCall(self::SESSION, [self::url(1)]),
        ])], $this->now);

        self::assertSame(1, $report->ordersMissing);
        self::assertSame([], $this->repository->sessions);
    }

    /** One order that cannot be written does not cost the others. */
    public function testOneFailingOrderDoesNotStopTheRest(): void
    {
        $this->repository->withOrder(self::STORE, 2);
        $this->repository->failFor[OrderCalls::keyOf(self::STORE, self::ORDER)] = true;

        $report = $this->catalog->record([
            new OrderCalls(self::STORE, self::ORDER, [new ListedCall(self::SESSION, [self::url(1)])]),
            new OrderCalls(self::STORE, 2, [new ListedCall('21403651', [self::url(2, '21403651')])]),
        ], $this->now);

        self::assertSame(1, $report->ordersFailed);
        self::assertSame([self::url(2, '21403651')], $this->repository->urlsOf(self::STORE, 2));
    }

    /** The backfill's dry run works everything out and writes nothing. */
    public function testADryRunWritesNothing(): void
    {
        $report = $this->catalog->record([
            new OrderCalls(self::STORE, self::ORDER, [new ListedCall(self::SESSION, [self::url(1)])]),
        ], $this->now, false);

        self::assertSame(1, $report->recordingsAdded);
        self::assertSame(0, $this->repository->applyCalls);
        self::assertSame([], $this->repository->sessions);
    }

    public function testTheSummaryNeverContainsALink(): void
    {
        $report = $this->record([self::url(1)]);

        self::assertStringNotContainsString('xrainbow', $report->describe());
        self::assertStringContainsString('1 new call recording link', $report->describe());
    }

    /** @param list<string> $urls */
    private function record(array $urls): CallRecordingReport
    {
        return $this->catalog->record([
            new OrderCalls(self::STORE, self::ORDER, [new ListedCall(self::SESSION, $urls)]),
        ], $this->now);
    }

    private static function url(int $n, string $session = self::SESSION): string
    {
        return 'https://order58.xrainbow.com/api/external/recording/fetch/' . $session
            . '?time=2026-07-29&company=SWCC&name=15594361-' . $n;
    }
}
