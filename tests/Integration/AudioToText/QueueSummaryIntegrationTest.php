<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioToText;

use App\AudioToText\Domain\QueueSummary;
use App\AudioToText\Infrastructure\DbTranscriptionJobRepository;
use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function bin2hex;
use function random_bytes;

/**
 * The four counters above `/audio-to-text` and `/audio-to-text/jobs`.
 *
 * ## Why every assertion is a delta
 *
 * `summary()` counts the whole installation — that is what the strip means, and it has no business
 * filtering by administrator. This suite therefore runs against a database that may hold real
 * conversations, so it takes a baseline first and asserts only the *change* its own rows produce.
 * Absolute assertions would pass on an empty developer machine and fail the moment anyone uploaded
 * anything, which is the kind of test that gets deleted rather than fixed.
 *
 * ## What this suite must never do
 *
 * It never calls `claimNextQueued()`. That method picks the oldest QUEUED row in the entire table, so
 * a test using it can claim a real administrator's pending upload and strand it in PROCESSING — which
 * has happened. Rows here are written with explicit statuses and timestamps instead, and every one is
 * removed by public id in `_after`. Nothing is ever deleted in bulk.
 *
 * Time is frozen with {@see MutableClock} so the 24-hour boundary is exact rather than "roughly now".
 */
final class QueueSummaryIntegrationTest extends Unit
{
    /** Far enough from any real row that the fixtures cannot collide with live data. */
    private const FROZEN_NOW = '2026-06-15 12:00:00';

    private ConnectionInterface $connection;
    private DbTranscriptionJobRepository $repository;
    private MutableClock $clock;
    private int $adminId;

    /** @var list<string> */
    private array $createdPublicIds = [];

    /** @var list<string> */
    private array $createdUsernames = [];

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->clock = new MutableClock(self::FROZEN_NOW);
        $this->repository = new DbTranscriptionJobRepository($this->connection, $this->clock);

        $username = 'a2t-summary-' . bin2hex(random_bytes(4));
        $this->createdUsernames[] = $username;
        $this->adminId = (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create($username, '$2y$10$notarealhashnotarealhashnotarealhashnotarealhashnotar');
    }

    protected function _after(): void
    {
        foreach ($this->createdPublicIds as $publicId) {
            IntegrationDb::cleanup($this->connection, '{{%audio_transcription_jobs}}', ['public_id' => $publicId]);
        }

        foreach ($this->createdUsernames as $username) {
            IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => $username]);
        }

        $this->createdPublicIds = [];
        $this->createdUsernames = [];
    }

    /**
     * The exact scenario from the bug report: a completed job whose speaker split needs review.
     *
     * This is the invariant the whole fix exists to protect. Transcription succeeding and speaker
     * separation being inconclusive are separate outcomes in separate columns, and only the first one
     * decides whether this is a completed job.
     */
    public function testACompletedJobCountsEvenWhenItsSpeakerSplitNeedsReview(): void
    {
        $before = $this->repository->summary();

        $this->seed('COMPLETED', completedAt: '-10 minutes', separation: 'NEEDS_REVIEW');
        $this->seed('COMPLETED', completedAt: '-20 minutes', separation: 'COMPLETED');
        $this->seed('COMPLETED', completedAt: '-30 minutes', separation: 'FAILED');
        $this->seed('COMPLETED', completedAt: '-40 minutes', separation: 'NOT_SUPPORTED');

        $after = $this->repository->summary();

        $this->assertSame(4, $after->completedLast24h - $before->completedLast24h);
    }

    /**
     * The regression that produced `COMPLETED (24H) 0` above nineteen completed jobs.
     *
     * The window used to be derived from `AUDIO_TRANSCRIPTION_RETENTION_SECONDS`. Setting retention to
     * 0 means "keep indefinitely", but it made the cutoff `now - 0 seconds` — the present instant — so
     * nothing could ever fall inside it. The window is now a property of the summary, and no setting
     * can collapse it.
     */
    public function testTheWindowIsNotDerivedFromRetention(): void
    {
        $before = $this->repository->summary();

        $this->seed('COMPLETED', completedAt: '-1 hour');

        $after = $this->repository->summary();

        $this->assertSame(1, $after->completedLast24h - $before->completedLast24h);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function terminalOffsets(): iterable
    {
        yield 'one minute ago' => ['-1 minute', true];
        yield 'one hour ago' => ['-1 hour', true];
        yield '23h59m ago' => ['-23 hours -59 minutes', true];
        // The boundary is inclusive: the comparison is `completed_at >= now - 24h`.
        yield 'exactly 24h ago' => ['-24 hours', true];
        yield '24h01m ago' => ['-24 hours -1 minute', false];
        yield '30 hours ago' => ['-30 hours', false];
        yield 'a week ago' => ['-7 days', false];
    }

    /**
     * @dataProvider terminalOffsets
     */
    public function testTheCompletedWindowBoundary(string $offset, bool $counted): void
    {
        $before = $this->repository->summary();
        $this->seed('COMPLETED', completedAt: $offset);
        $after = $this->repository->summary();

        $this->assertSame($counted ? 1 : 0, $after->completedLast24h - $before->completedLast24h);
    }

    /**
     * @dataProvider terminalOffsets
     */
    public function testTheFailedWindowBoundary(string $offset, bool $counted): void
    {
        $before = $this->repository->summary();
        $this->seed('FAILED', completedAt: $offset);
        $after = $this->repository->summary();

        $this->assertSame($counted ? 1 : 0, $after->failedLast24h - $before->failedLast24h);
    }

    /** A terminal job is counted by when it *finished*, not by when it was uploaded. */
    public function testAJobUploadedLongAgoButFinishedRecentlyCounts(): void
    {
        $before = $this->repository->summary();

        $this->seed('COMPLETED', completedAt: '-15 minutes', createdAt: '-5 days');

        $after = $this->repository->summary();

        $this->assertSame(1, $after->completedLast24h - $before->completedLast24h);
    }

    /** And the reverse: uploaded inside the window, finished before it. */
    public function testAJobUploadedRecentlyButFinishedLongAgoDoesNotCount(): void
    {
        $before = $this->repository->summary();

        // Physically odd, but it is exactly what `created_at >= cutoff` used to count.
        $this->seed('COMPLETED', completedAt: '-40 hours', createdAt: '-1 hour');

        $after = $this->repository->summary();

        $this->assertSame(0, $after->completedLast24h - $before->completedLast24h);
    }

    /**
     * All four counters at once, from the mixed fixture in the brief.
     *
     * 2 queued · 1 transcribing · 1 diarizing · 4 completed inside the window · 3 outside ·
     * 2 failed inside · 1 outside.
     */
    public function testAllFourCountersOnMixedData(): void
    {
        $before = $this->repository->summary();

        $this->seed('QUEUED', stage: 'QUEUED');
        $this->seed('QUEUED', stage: 'QUEUED');

        $this->seed('PROCESSING', stage: 'TRANSCRIBING', startedAt: '-2 minutes');
        $this->seed('PROCESSING', stage: 'DIARIZING', startedAt: '-3 minutes');

        foreach (['-1 hour', '-6 hours', '-12 hours', '-23 hours'] as $offset) {
            $this->seed('COMPLETED', completedAt: $offset);
        }

        foreach (['-25 hours', '-3 days', '-2 weeks'] as $offset) {
            $this->seed('COMPLETED', completedAt: $offset);
        }

        foreach (['-30 minutes', '-20 hours'] as $offset) {
            $this->seed('FAILED', completedAt: $offset);
        }

        $this->seed('FAILED', completedAt: '-40 hours');

        $after = $this->repository->summary();

        $this->assertSame(2, $after->queued - $before->queued, 'QUEUED');
        $this->assertSame(2, $after->processing - $before->processing, 'PROCESSING');
        $this->assertSame(4, $after->completedLast24h - $before->completedLast24h, 'COMPLETED (24h)');
        $this->assertSame(2, $after->failedLast24h - $before->failedLast24h, 'FAILED (24h)');
    }

    /** Terminal jobs are not active work, however recently they finished. */
    public function testTerminalJobsAreNeverCountedAsQueuedOrProcessing(): void
    {
        $before = $this->repository->summary();

        $this->seed('COMPLETED', completedAt: '-1 minute');
        $this->seed('FAILED', completedAt: '-1 minute');

        $after = $this->repository->summary();

        $this->assertSame(0, $after->queued - $before->queued);
        $this->assertSame(0, $after->processing - $before->processing);
    }

    /** The active counters have no window — a job queued last week is still waiting. */
    public function testQueuedAndProcessingIgnoreTheWindow(): void
    {
        $before = $this->repository->summary();

        $this->seed('QUEUED', stage: 'QUEUED', createdAt: '-9 days');
        $this->seed('PROCESSING', stage: 'TRANSCRIBING', createdAt: '-9 days', startedAt: '-9 days');

        $after = $this->repository->summary();

        $this->assertSame(1, $after->queued - $before->queued);
        $this->assertSame(1, $after->processing - $before->processing);
    }

    /** Moving the clock forward moves the window with it, without touching a row. */
    public function testTheWindowFollowsTheClock(): void
    {
        $before = $this->repository->summary();
        $this->seed('COMPLETED', completedAt: '-23 hours');

        $this->assertSame(1, $this->repository->summary()->completedLast24h - $before->completedLast24h);

        // Two hours later the same row is 25 hours old and drops out.
        $this->clock->advance('+2 hours');

        $this->assertSame(0, $this->repository->summary()->completedLast24h - $before->completedLast24h);
    }

    public function testTheWindowLabelMatchesTheConstant(): void
    {
        $this->assertSame('24h', QueueSummary::windowLabel());
        $this->assertSame(24, QueueSummary::WINDOW_HOURS);
    }

    /**
     * Writes one job row directly, with exactly the status and timestamps the case needs.
     *
     * Deliberately an INSERT rather than `create()` + `claimNextQueued()` + `markCompleted()`: driving
     * the real transitions would mean claiming from a shared queue, and this suite must never do that.
     * Offsets are relative to the frozen clock, so every row's age is exact.
     */
    private function seed(
        string $status,
        ?string $completedAt = null,
        ?string $createdAt = null,
        ?string $startedAt = null,
        string $stage = 'COMPLETED',
        ?string $separation = null,
    ): string {
        $publicId = bin2hex(random_bytes(16));
        $this->createdPublicIds[] = $publicId;

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => $publicId,
            'uploaded_by_admin_id' => $this->adminId,
            'status' => $status,
            'processing_stage' => $stage,
            'original_filename' => 'summary-fixture.wav',
            'created_at' => $this->at($createdAt ?? $completedAt ?? '-1 minute'),
            'started_at' => $startedAt === null ? null : $this->at($startedAt),
            'completed_at' => $completedAt === null ? null : $this->at($completedAt),
            'speaker_separation_status' => $separation,
        ])->execute();

        return $publicId;
    }

    private function at(string $offset): string
    {
        $base = new DateTimeImmutable(self::FROZEN_NOW, new DateTimeZone('UTC'));

        return DbDateTime::format($base->modify($offset));
    }
}
