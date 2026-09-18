<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioToText;

use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\Tts\TtsEnqueueOutcome;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsStatus;
use App\AudioToText\Infrastructure\DbAudioConversationRepository;
use App\AudioToText\Infrastructure\DbTranscriptionJobRepository;
use App\AudioToText\Infrastructure\Tts\DbTtsRenditionRepository;
use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function bin2hex;
use function random_bytes;
use function random_int;

/**
 * The cost-protection rules, against real MySQL.
 *
 * These are **database** guarantees, not code paths — the conditional update, the unique key, the
 * compare-and-swap on a token — so a test double proving the right methods were called would prove
 * nothing at all. Every one of them exists to stop a second charge for one recording, and the failure
 * mode of each is money leaving the account rather than an exception somebody notices.
 *
 * **This test never calls the real `claim()` against shared state it did not create**: every row it
 * writes it also removes, and it touches no row belonging to anything else, so it is safe to run beside
 * real work sitting in the queue.
 */
final class TtsRenditionRepositoryTest extends Unit
{
    private ConnectionInterface $connection;
    private DbTtsRenditionRepository $renditions;
    private DbTranscriptionJobRepository $jobs;
    private DbAudioConversationRepository $conversations;

    private int $adminId;
    private int $storeSourceId;
    private int $jobId;
    private string $jobPublicId;

    /** @var list<string> */
    private array $createdUsernames = [];

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $clock = new SystemClock();

        $this->renditions = new DbTtsRenditionRepository($this->connection, $clock);
        $this->jobs = new DbTranscriptionJobRepository($this->connection, $clock);
        $this->conversations = new DbAudioConversationRepository($this->connection);

        $username = 'a2t-tts-' . bin2hex(random_bytes(6));
        (new DbAdminUserRepository($this->connection, $clock))->create($username, 'x');
        $this->createdUsernames[] = $username;

        /** @var array<string, mixed> $row */
        $row = $this->connection
            ->createCommand('SELECT id FROM {{%admin_users}} WHERE username = :u', [':u' => $username])
            ->queryOne();
        $this->adminId = (int) $row['id'];

        // Well outside anything the store mirror holds, so nothing here can be mistaken for real history.
        $this->storeSourceId = 900_000_000 + random_int(1, 999_999);

        $conversationId = $this->conversations->create(
            bin2hex(random_bytes(16)),
            $this->storeSourceId,
            ConversationMode::Common,
            $this->adminId,
            new DateTimeImmutable(),
            true,
        );

        $this->jobPublicId = bin2hex(random_bytes(16));
        $this->jobs->create(
            $this->jobPublicId,
            $this->adminId,
            'call.wav',
            'source.wav',
            12.5,
            null,
            $conversationId,
            SourceRole::Common,
        );

        /** @var array<string, mixed> $jobRow */
        $jobRow = $this->connection
            ->createCommand('SELECT id FROM {{%audio_transcription_jobs}} WHERE public_id = :p', [':p' => $this->jobPublicId])
            ->queryOne();
        $this->jobId = (int) $jobRow['id'];
    }

    protected function _after(): void
    {
        // Renditions cascade from the job, but they are removed explicitly so a failure part-way through
        // setup still leaves nothing behind.
        $this->connection->createCommand(
            'DELETE r FROM {{%audio_tts_renditions}} r
             JOIN {{%audio_transcription_jobs}} j ON j.id = r.job_id
             JOIN {{%audio_conversations}} c ON c.id = j.conversation_id
             WHERE c.store_source_id = :s',
            [':s' => $this->storeSourceId],
        )->execute();

        $this->connection->createCommand(
            'DELETE j FROM {{%audio_transcription_jobs}} j
             JOIN {{%audio_conversations}} c ON c.id = j.conversation_id
             WHERE c.store_source_id = :s',
            [':s' => $this->storeSourceId],
        )->execute();

        IntegrationDb::cleanup($this->connection, '{{%audio_conversations}}', ['store_source_id' => $this->storeSourceId]);

        foreach ($this->createdUsernames as $username) {
            IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => $username]);
        }

        $this->createdUsernames = [];
    }

    // ------------------------------------------------------------------ the upload flag round-trips

    public function testTheUploadRequestIsStoredAndReadBack(): void
    {
        $this->assertTrue($this->conversations->generatesAiAudio($this->conversationId()));
    }

    public function testAnUploadThatDidNotAskIsRecordedAsSuch(): void
    {
        $id = $this->conversations->create(
            bin2hex(random_bytes(16)),
            $this->storeSourceId,
            ConversationMode::Common,
            $this->adminId,
            new DateTimeImmutable(),
        );

        $this->assertFalse($this->conversations->generatesAiAudio($id), 'Off by default has to mean off.');
    }

    // ------------------------------------------------------------------ enqueue is idempotent

    public function testQueuingTwiceProducesOneRow(): void
    {
        $this->assertSame(TtsEnqueueOutcome::Queued, $this->enqueue('hash-one'));
        $this->assertSame(TtsEnqueueOutcome::AlreadyRunning, $this->enqueue('hash-one'));

        $this->assertSame(1, $this->rowCount(), 'The unique key must keep this to one rendition.');
    }

    /**
     * **The bug an `ON DUPLICATE KEY UPDATE status='QUEUED'` upsert would have.**
     *
     * Resetting a row a worker is mid-way through generating means the next tick claims it, two workers
     * synthesise the same conversation at once, both bill for it, and both race to write the filename.
     * Nothing about the unique key prevents that — it is one row written twice, not two rows.
     */
    public function testARowBeingGeneratedIsNeverResetToQueued(): void
    {
        $this->enqueue('hash-one');
        $claimed = $this->renditions->claim();

        $this->assertNotNull($claimed);
        $this->assertSame(TtsStatus::Generating, $claimed->status);

        $this->assertSame(
            TtsEnqueueOutcome::AlreadyRunning,
            $this->enqueue('hash-two'),
            'A generation in flight must be left exactly as it is.',
        );

        $after = $this->renditions->findForJob($this->jobId, TtsOutputType::Mixed);

        $this->assertSame(TtsStatus::Generating, $after?->status);
        $this->assertSame('hash-one', $after->requestedHash, 'The in-flight attempt keeps its own target.');
    }

    public function testAFailedRenditionMayBeQueuedAgain(): void
    {
        $this->enqueue('hash-one');
        $claimed = $this->renditions->claim();
        $this->assertNotNull($claimed);
        $this->renditions->markFailed($claimed->id, $claimed->attemptToken, 'Deepgram was unreachable.');

        $this->assertSame(TtsEnqueueOutcome::Queued, $this->enqueue('hash-two'));
        $this->assertSame(1, $this->rowCount());
    }

    // ------------------------------------------------------------------ claiming

    public function testTwoClaimsProduceOneWinner(): void
    {
        $this->enqueue('hash-one');

        $first = $this->renditions->claim();
        $second = $this->renditions->claim();

        $this->assertNotNull($first);
        $this->assertNull($second, 'The conditional update and the selection are the same statement.');
    }

    public function testClaimingCountsTheAttempt(): void
    {
        $this->enqueue('hash-one');
        $claimed = $this->renditions->claim();

        $this->assertSame(1, $claimed?->attempts);
        $this->assertNotNull($claimed->generatingSince, 'The stale sweep needs something unambiguous to measure.');
    }

    // ------------------------------------------------------------------ publishing

    public function testAReadyRenditionRecordsWhatItCost(): void
    {
        $this->enqueue('hash-one');
        $claimed = $this->renditions->claim();
        $this->assertNotNull($claimed);

        $published = $this->renditions->markReady(
            $claimed->id,
            $claimed->attemptToken,
            'ai-mixed-abcdef01.mp3',
            'hash-one',
            'v1|mp3|24000hz|max1900|c=thalia|a=arcas|gap350',
            98_304,
            412,
            2,
            'aura-2-thalia-en',
            'aura-2-arcas-en',
        );

        $this->assertTrue($published);

        $row = $this->renditions->findForJob($this->jobId, TtsOutputType::Mixed);

        $this->assertSame(TtsStatus::Ready, $row?->status);
        $this->assertSame('ai-mixed-abcdef01.mp3', $row->fileName);
        $this->assertSame(412, $row->characterCount, 'Billed characters, so an invoice can be reconciled.');
        $this->assertSame(2, $row->requestCount);
        $this->assertTrue($row->isCurrent('hash-one', 'v1|mp3|24000hz|max1900|c=thalia|a=arcas|gap350'));
    }

    /**
     * A slow worker whose row was re-queued underneath it must not publish over the newer attempt.
     *
     * Without the token comparison the older generation wins purely by finishing last, and the file the
     * administrator asked for is replaced by one made from a transcript they already corrected away.
     */
    public function testASupersededAttemptCannotPublish(): void
    {
        $this->enqueue('hash-one');
        $stale = $this->renditions->claim();
        $this->assertNotNull($stale);

        // Somebody corrects the transcript and presses Regenerate while the first run is still going.
        $this->renditions->markFailed($stale->id, $stale->attemptToken, 'pretend this finished');
        $this->enqueue('hash-two');

        $published = $this->renditions->markReady(
            $stale->id,
            $stale->attemptToken,
            'ai-mixed-00000000.mp3',
            'hash-one',
            'render-key',
            1,
            1,
            1,
            null,
            null,
        );

        $this->assertFalse($published, 'The caller is told, so it can delete the file it just made.');
        $this->assertNull($this->renditions->findForJob($this->jobId, TtsOutputType::Mixed)?->fileName);
    }

    // ------------------------------------------------------------------ a failed regeneration

    /**
     * **The rule that makes Regenerate safe to press.**
     *
     * A failure must leave the audio it was trying to replace exactly as playable as it was before
     * anybody pressed the button.
     */
    public function testAFailedRegenerationKeepsThePreviousFile(): void
    {
        $this->enqueue('hash-one');
        $first = $this->renditions->claim();
        $this->assertNotNull($first);
        $this->renditions->markReady(
            $first->id,
            $first->attemptToken,
            'ai-mixed-11111111.mp3',
            'hash-one',
            'render-key',
            1000,
            100,
            1,
            'aura-2-thalia-en',
            'aura-2-arcas-en',
        );

        // The transcript is corrected, and the regeneration fails.
        $this->enqueue('hash-two');
        $second = $this->renditions->claim();
        $this->assertNotNull($second);
        $this->renditions->markFailed($second->id, $second->attemptToken, 'The speech provider is rate limiting.');

        $row = $this->renditions->findForJob($this->jobId, TtsOutputType::Mixed);

        $this->assertSame(TtsStatus::Failed, $row?->status);
        $this->assertTrue($row->isPlayable(), 'The previous recording must still play.');
        $this->assertSame('ai-mixed-11111111.mp3', $row->fileName);
        $this->assertSame('hash-one', $row->fileHash);
        $this->assertTrue($row->isStale('hash-two'), 'And it is honestly reported as out of date.');
    }

    // ------------------------------------------------------------------ abandoned rows

    /**
     * A row left GENERATING is otherwise unreachable forever: the claim only looks at QUEUED, and the
     * enqueue correctly refuses anything in flight. The administrator would watch "Generating" with no
     * button that could help.
     */
    public function testAnAbandonedRenditionIsRecoveredToAFailureAndNotRetried(): void
    {
        $this->enqueue('hash-one');
        $claimed = $this->renditions->claim();
        $this->assertNotNull($claimed);

        // Pretend the worker was killed an hour ago.
        $this->connection->createCommand(
            'UPDATE {{%audio_tts_renditions}} SET generating_since = :t WHERE id = :id',
            [':t' => '2020-01-01 00:00:00', ':id' => $claimed->id],
        )->execute();

        $this->assertSame(1, $this->renditions->recoverStale(3600));

        $row = $this->renditions->findForJob($this->jobId, TtsOutputType::Mixed);

        $this->assertSame(TtsStatus::Failed, $row?->status);
        $this->assertNull($row->generatingSince);
        $this->assertNull(
            $this->renditions->claim(),
            'Recovered, not retried: a crash may repeat, and here every repetition is billed.',
        );
    }

    public function testARunningRenditionIsNotRecovered(): void
    {
        $this->enqueue('hash-one');
        $this->renditions->claim();

        $this->assertSame(0, $this->renditions->recoverStale(3600), 'A slow generation is not an abandoned one.');
    }

    // ------------------------------------------------------------------ cascade

    public function testDeletingTheRecordingRemovesItsRenditions(): void
    {
        $this->enqueue('hash-one');
        $this->assertSame(1, $this->rowCount());

        $this->connection
            ->createCommand('DELETE FROM {{%audio_transcription_jobs}} WHERE id = :id', [':id' => $this->jobId])
            ->execute();

        $this->assertSame(0, $this->rowCount(), 'Generated audio is meaningless without the recording.');
    }

    private function enqueue(string $hash): TtsEnqueueOutcome
    {
        return $this->renditions->enqueue($this->jobId, TtsOutputType::Mixed, $hash, $this->adminId, 'DEEPGRAM');
    }

    private function rowCount(): int
    {
        return (int) $this->connection
            ->createCommand('SELECT COUNT(*) FROM {{%audio_tts_renditions}} WHERE job_id = :id', [':id' => $this->jobId])
            ->queryScalar();
    }

    private function conversationId(): int
    {
        /** @var array<string, mixed> $row */
        $row = $this->connection
            ->createCommand('SELECT conversation_id FROM {{%audio_transcription_jobs}} WHERE id = :id', [':id' => $this->jobId])
            ->queryOne();

        return (int) $row['conversation_id'];
    }
}
