<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Order58\Application\CallRecordings\CallRecordingCatalog;
use App\Order58\Console\BackfillCallRecordingsCommand;
use App\Order58\Domain\CallRecordings\ListedCall;
use App\Order58\Domain\CallRecordings\OrderCalls;
use App\Order58\Infrastructure\DbCallRecordingRepository;
use App\Shared\Infrastructure\Db\DatabaseTarget;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Query\Query;

use function hash;
use function json_encode;

/**
 * The call recording tables against real MySQL: identity, scoping, cascade and the backfill.
 *
 * Every row lives under fixture stores in the `9876559xx` range, and the orders it creates are its own.
 * Nothing here reaches the network.
 */
final class CallRecordingRepositoryTest extends Unit
{
    private const STORE = 987655971;
    private const OTHER_STORE = 987655972;
    private const ORDER = 919900101;
    private const OTHER_ORDER = 919900102;
    private const SESSION = '919900801';

    private ConnectionInterface $connection;
    private DbCallRecordingRepository $repository;
    private CallRecordingCatalog $catalog;
    private DateTimeImmutable $now;
    private bool $ready = false;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        DatabaseTarget::resolve($this->connection)->assertLocalForSchemaChange();

        $this->ready = true;
        $this->repository = new DbCallRecordingRepository($this->connection);
        $this->catalog = new CallRecordingCatalog($this->repository, new NullLogger());
        $this->now = new DateTimeImmutable('2026-10-27 12:00:00');

        $this->cleanup();
    }

    protected function _after(): void
    {
        if ($this->ready) {
            $this->cleanup();
        }
    }

    public function testLinksAreStoredExactlyAsListed(): void
    {
        $this->order(self::STORE, self::ORDER);

        $this->record(self::STORE, self::ORDER, [self::url(1), self::url(2)]);

        $location = $this->repository->locate(self::STORE, self::ORDER, $this->recordingId(self::url(2)));

        self::assertNotNull($location);
        self::assertSame(self::url(2), $location->url, 'byte for byte');
        self::assertSame(1, $location->position);
        self::assertSame(self::SESSION, $location->callSessionId);
    }

    /** Two passes over the same listing leave exactly one session and one row per link. */
    public function testTheUniqueKeysHoldAcrossRepeatedPasses(): void
    {
        $this->order(self::STORE, self::ORDER);

        foreach ([1, 2, 3] as $ignored) {
            $this->record(self::STORE, self::ORDER, [self::url(1), self::url(1), self::url(2)]);
        }

        self::assertSame(1, $this->rowCount('{{%order58_call_sessions}}', ['store_source_id' => self::STORE]));
        self::assertSame(2, $this->recordingCount());
    }

    /** The database refuses a second copy of a link even if a caller tried to write one. */
    public function testTheDatabaseItselfRefusesADuplicateLink(): void
    {
        $this->order(self::STORE, self::ORDER);
        $this->record(self::STORE, self::ORDER, [self::url(1)]);

        $sessionRow = (int) (new Query($this->connection))
            ->select('id')->from('{{%order58_call_sessions}}')->where(['store_source_id' => self::STORE])->scalar();

        $this->expectException(IntegrityException::class);

        $this->connection->createCommand()->insert('{{%order58_call_recordings}}', [
            'session_id' => $sessionRow,
            'position' => 5,
            'url' => self::url(1),
            'url_hash' => hash('sha256', self::url(1)),
            'created_at' => '2026-10-27 12:00:00',
            'updated_at' => '2026-10-27 12:00:00',
        ])->execute();
    }

    /**
     * **A recording is found only through its own order of its own store.**
     *
     * The same recording id asked for under another order or another store answers nothing, so an id
     * read off one page cannot be replayed against another.
     */
    public function testARecordingIsOnlyFoundThroughItsOwnOrderAndStore(): void
    {
        $this->order(self::STORE, self::ORDER);
        $this->order(self::STORE, self::OTHER_ORDER);
        $this->order(self::OTHER_STORE, self::ORDER);
        $this->record(self::STORE, self::ORDER, [self::url(1)]);

        $id = $this->recordingId(self::url(1));

        self::assertNotNull($this->repository->locate(self::STORE, self::ORDER, $id));
        self::assertNull($this->repository->locate(self::STORE, self::OTHER_ORDER, $id), 'another order');
        self::assertNull($this->repository->locate(self::OTHER_STORE, self::ORDER, $id), 'another store');
        self::assertNull($this->repository->locate(self::STORE, self::ORDER, $id + 100000), 'no such id');
    }

    /** A link the provider replaced is kept, but it is not served any more. */
    public function testAReplacedLinkIsKeptButNotServed(): void
    {
        $this->order(self::STORE, self::ORDER);
        $this->record(self::STORE, self::ORDER, [self::url(1)]);
        $old = $this->recordingId(self::url(1));

        $this->record(self::STORE, self::ORDER, [self::url(2)]);

        self::assertSame(2, $this->recordingCount(), 'nothing deleted');
        self::assertNull($this->repository->locate(self::STORE, self::ORDER, $old));
        self::assertNotNull($this->repository->locate(self::STORE, self::ORDER, $this->recordingId(self::url(2))));
    }

    /** Recordings go with their order, exactly as its lines do, and with nothing else. */
    public function testDeletingAnOrderTakesItsRecordingsWithIt(): void
    {
        $this->order(self::STORE, self::ORDER);
        $this->order(self::STORE, self::OTHER_ORDER);
        $this->record(self::STORE, self::ORDER, [self::url(1)]);
        $this->record(self::STORE, self::OTHER_ORDER, [self::url(2)], '919900802');

        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => self::STORE, 'source_order_id' => self::ORDER])
            ->execute();

        self::assertSame(1, $this->rowCount('{{%order58_call_sessions}}', ['store_source_id' => self::STORE]));
        self::assertSame(1, $this->recordingCount());
    }

    /** A session cannot exist for an order that does not. */
    public function testASessionNeedsItsOrder(): void
    {
        $this->expectException(IntegrityException::class);

        $this->connection->createCommand()->insert('{{%order58_call_sessions}}', [
            'store_source_id' => self::STORE,
            'source_order_id' => self::ORDER,
            'call_session_id' => self::SESSION,
            'created_at' => '2026-10-27 12:00:00',
            'updated_at' => '2026-10-27 12:00:00',
        ])->execute();
    }

    /**
     * **The backfill reads the stored payloads, never the API, and is safe to repeat.**
     *
     * A dry run writes nothing; `--write` stores the links; a second `--write` finds nothing to do.
     */
    public function testTheBackfillFillsFromPayloadsAndIsIdempotent(): void
    {
        $this->order(self::STORE, self::ORDER, [
            'session_id' => self::SESSION,
            'wav_download_urls' => [self::url(1), self::url(2)],
        ]);
        $this->order(self::STORE, self::OTHER_ORDER, null);

        $dry = $this->backfill(false);
        self::assertSame(0, $dry->getStatusCode());
        self::assertStringContainsString('Recording links to add: 2', $dry->getDisplay());
        self::assertSame(0, $this->recordingCount(), 'a dry run writes nothing');

        $write = $this->backfill(true);
        self::assertStringContainsString('Recording links added: 2', $write->getDisplay());
        self::assertSame(2, $this->recordingCount());

        $again = $this->backfill(true);
        self::assertStringContainsString('Recording links added: 0', $again->getDisplay());
        self::assertStringContainsString('Orders already up to date: 1', $again->getDisplay());
        self::assertSame(2, $this->recordingCount());
    }

    private function backfill(bool $write): CommandTester
    {
        $tester = new CommandTester(new BackfillCallRecordingsCommand(
            $this->repository,
            $this->catalog,
            new MutableClock('2026-10-27 12:00:00'),
        ));

        $tester->execute(['--store' => (string) self::STORE] + ($write ? ['--write' => true] : []));

        return $tester;
    }

    /** @param list<string> $urls */
    private function record(int $store, int $order, array $urls, string $session = self::SESSION): void
    {
        $this->catalog->record([new OrderCalls($store, $order, [new ListedCall($session, $urls)])], $this->now);
    }

    /** A minimal mirrored order, with `calls` in its payload when given. */
    private function order(int $store, int $order, ?array $calls = null): void
    {
        $payload = ['id' => (string) $order, 'account_id' => (string) $store];

        if ($calls !== null) {
            $payload['calls'] = $calls;
        }

        $this->connection->createCommand()->insert('{{%order58_orders}}', [
            'account_id' => $store,
            'source_order_id' => $order,
            'payload_json' => (string) json_encode($payload),
            'content_hash' => hash('sha256', (string) $order),
            'synced_at' => '2026-10-27 12:00:00',
            'created_at' => '2026-10-27 12:00:00',
            'updated_at' => '2026-10-27 12:00:00',
        ])->execute();
    }

    private function recordingId(string $url): int
    {
        return (int) (new Query($this->connection))
            ->select('r.id')
            ->from(['r' => '{{%order58_call_recordings}}'])
            ->innerJoin(['s' => '{{%order58_call_sessions}}'], 's.id = r.session_id')
            ->where(['r.url_hash' => hash('sha256', $url)])
            ->andWhere(['s.store_source_id' => [self::STORE, self::OTHER_STORE]])
            ->scalar();
    }

    private function recordingCount(): int
    {
        return (int) (new Query($this->connection))
            ->from(['r' => '{{%order58_call_recordings}}'])
            ->innerJoin(['s' => '{{%order58_call_sessions}}'], 's.id = r.session_id')
            ->where(['s.store_source_id' => [self::STORE, self::OTHER_STORE]])
            ->count();
    }

    /** @param array<string, mixed> $where */
    private function rowCount(string $table, array $where): int
    {
        return (int) (new Query($this->connection))->from($table)->where($where)->count();
    }

    private static function url(int $n): string
    {
        return 'https://order58.xrainbow.com/api/external/recording/fetch/' . self::SESSION
            . '?time=2026-10-27&company=SWCC&name=' . self::ORDER . '-' . $n;
    }

    /** The fixture stores' orders; sessions and recordings follow by cascade. Nothing else. */
    private function cleanup(): void
    {
        $this->connection->createCommand()
            ->delete('{{%order58_orders}}', ['account_id' => [self::STORE, self::OTHER_STORE]])
            ->execute();
    }
}
