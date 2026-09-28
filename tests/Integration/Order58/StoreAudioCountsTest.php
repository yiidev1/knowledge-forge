<?php

declare(strict_types=1);

namespace App\Tests\Integration\Order58;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Order58\Infrastructure\DbStoreAudioCounts;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function bin2hex;
use function gmdate;
use function random_bytes;
use function PHPUnit\Framework\assertSame;

/**
 * The store-audio card counts, against real MySQL.
 *
 * ## What is really being guarded
 *
 * Two things that would be invisible if they broke:
 *
 * 1. **`total` must keep meaning what it has always meant** — rows in `audio_conversations` for the
 *    store. The same number drives the "Uploaded audio" filter, so a change here silently changes which
 *    stores that filter shows.
 * 2. **`recording_type` is nullable**, so the three named types do NOT add up to the total. A future
 *    change that quietly counted null as MIXED would claim a channel nobody stated, and every card
 *    would still look plausible.
 */
final class StoreAudioCountsTest extends Unit
{
    /** Far outside the mirrored range, so nothing here can collide with real data. */
    private const STORE_A = 987655400;
    private const STORE_B = 987655401;
    private const STORE_EMPTY = 987655402;

    private const USERNAME = '__kf_audio_counts_admin__';

    private ConnectionInterface $connection;
    private DbStoreAudioCounts $counts;
    private int $adminId;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->adminId = (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash('AudioCountsPassw0rd!secure'));

        $this->counts = new DbStoreAudioCounts($this->connection);
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ the breakdown

    /** Each named type is counted into its own bucket, and only for its own store. */
    public function testEachRecordingTypeIsCountedSeparately(): void
    {
        $this->conversation(self::STORE_A, 'MIXED');
        $this->conversation(self::STORE_A, 'MIXED');
        $this->conversation(self::STORE_A, 'CALLER');
        $this->conversation(self::STORE_A, 'CALLEE');

        $b = $this->counts->countsFor([self::STORE_A])[self::STORE_A];

        assertSame(4, $b->total);
        assertSame(2, $b->mixed);
        assertSame(1, $b->caller);
        assertSame(1, $b->callee);
        assertSame(0, $b->other());
    }

    /**
     * The one that matters most: a null recording type is reported as Other, never as Mixed.
     *
     * Most rows in this database are null - the column is newer than the feature - so folding them
     * into Mixed would be both wrong and completely believable on screen.
     */
    public function testNullRecordingTypeIsReportedAsOtherAndNeverAsMixed(): void
    {
        $this->conversation(self::STORE_A, 'MIXED');
        $this->conversation(self::STORE_A, null);
        $this->conversation(self::STORE_A, null);
        $this->conversation(self::STORE_A, null);

        $b = $this->counts->countsFor([self::STORE_A])[self::STORE_A];

        assertSame(4, $b->total);
        assertSame(1, $b->mixed, 'A null recording type must not be counted as Mixed.');
        assertSame(3, $b->other());
    }

    /** A SEPARATE upload records no recording type; its mode already says what it is. */
    public function testASeparateUploadCountsAsOneConversationUnderOther(): void
    {
        $this->conversation(self::STORE_A, null, mode: 'SEPARATE');

        $b = $this->counts->countsFor([self::STORE_A])[self::STORE_A];

        assertSame(1, $b->total, 'A Customer + Agent pair is ONE conversation, not two.');
        assertSame(1, $b->other());
    }

    /** Other is always total minus the three, so the four values can never disagree. */
    public function testOtherIsAlwaysTheRemainder(): void
    {
        $this->conversation(self::STORE_A, 'MIXED');
        $this->conversation(self::STORE_A, 'CALLER');
        $this->conversation(self::STORE_A, null);
        $this->conversation(self::STORE_A, null);
        $this->conversation(self::STORE_A, null);

        $b = $this->counts->countsFor([self::STORE_A])[self::STORE_A];

        assertSame($b->total - $b->mixed - $b->caller - $b->callee, $b->other());
        assertSame(3, $b->other());
    }

    // ------------------------------------------------------------------ isolation

    /** Counts never leak between stores. */
    public function testCountsAreIsolatedByStoreSourceId(): void
    {
        $this->conversation(self::STORE_A, 'MIXED');
        $this->conversation(self::STORE_A, 'MIXED');
        $this->conversation(self::STORE_B, 'CALLER');

        $result = $this->counts->countsFor([self::STORE_A, self::STORE_B]);

        assertSame(2, $result[self::STORE_A]->total);
        assertSame(2, $result[self::STORE_A]->mixed);
        assertSame(0, $result[self::STORE_A]->caller);

        assertSame(1, $result[self::STORE_B]->total);
        assertSame(1, $result[self::STORE_B]->caller);
        assertSame(0, $result[self::STORE_B]->mixed);
    }

    /**
     * A store with nothing is ABSENT from the result, not present as a zero row.
     *
     * The card defaults, and an absent key and a zero mean the same thing - so the query does not have
     * to carry rows that say nothing.
     */
    public function testAStoreWithNoAudioIsAbsentRatherThanZero(): void
    {
        $this->conversation(self::STORE_A, 'MIXED');

        $result = $this->counts->countsFor([self::STORE_A, self::STORE_EMPTY]);

        self::assertArrayHasKey(self::STORE_A, $result);
        self::assertArrayNotHasKey(self::STORE_EMPTY, $result);
    }

    /** No ids, no query. */
    public function testAnEmptyIdListReturnsNothing(): void
    {
        assertSame([], $this->counts->countsFor([]));
    }

    // ------------------------------------------------------------------ performance

    /**
     * The N+1 guard.
     *
     * A page shows up to 36 stores. If the breakdown ever became a query per card this would be 36
     * round trips to print a few dozen numbers, and nothing on screen would look different - which is
     * exactly why it needs a test rather than a code review.
     */
    public function testTheBreakdownForManyStoresIsOneQuery(): void
    {
        $ids = [];

        for ($i = 0; $i < 12; $i++) {
            $id = self::STORE_A + 100 + $i;
            $ids[] = $id;
            $this->conversation($id, $i % 2 === 0 ? 'MIXED' : 'CALLER');
        }

        // The probe is itself a statement, so two back-to-back readings differ by the probe's own
        // cost. Measure that first and subtract it, rather than hard-coding an offset that would
        // silently drift if the probe ever changed.
        $calibrationStart = $this->queryCount();
        $overhead = $this->queryCount() - $calibrationStart;

        $before = $this->queryCount();
        $result = $this->counts->countsFor($ids);
        $issued = $this->queryCount() - $before - $overhead;

        assertSame(12, count($result));
        assertSame(
            1,
            $issued,
            'countsFor() must stay ONE grouped query however many stores a page shows.',
        );
    }

    // ------------------------------------------------------------------ the filter source

    /**
     * `storesWithAudio()` still keys on "has a conversation at all", which is what "Uploaded audio"
     * has always meant. The breakdown must not have redefined it.
     */
    public function testStoresWithAudioIgnoresRecordingTypeEntirely(): void
    {
        $this->conversation(self::STORE_A, null);
        $this->conversation(self::STORE_B, 'CALLEE');

        $withAudio = $this->counts->storesWithAudio();

        self::assertContains(self::STORE_A, $withAudio, 'A null recording type is still uploaded audio.');
        self::assertContains(self::STORE_B, $withAudio);
        self::assertNotContains(self::STORE_EMPTY, $withAudio);
    }

    // ------------------------------------------------------------------ plumbing

    private function conversation(int $store, ?string $recordingType, string $mode = 'COMMON'): void
    {
        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'store_source_id' => $store,
            'mode' => $mode,
            'recording_type' => $recordingType,
            'order_id' => null,
            'generate_ai_audio' => 0,
            'uploaded_by_admin_id' => $this->adminId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();
    }

    /** MySQL's own count of statements this session has issued. */
    private function queryCount(): int
    {
        /** @var array{Variable_name: string, Value: string}|false $row */
        $row = $this->connection
            ->createCommand("SHOW SESSION STATUS LIKE 'Questions'")
            ->queryOne();

        return (int) ($row['Value'] ?? 0);
    }

    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();

        $ids = [self::STORE_A, self::STORE_B, self::STORE_EMPTY];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = self::STORE_A + 100 + $i;
        }

        $connection->createCommand()->delete('{{%audio_conversations}}', ['store_source_id' => $ids])->execute();
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
