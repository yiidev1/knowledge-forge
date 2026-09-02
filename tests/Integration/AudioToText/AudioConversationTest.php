<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioToText;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\QueuedAudioStorage;
use App\AudioToText\Application\TranscriptionQueue;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Infrastructure\AudioDurationProbe;
use App\AudioToText\Infrastructure\DbAudioConversationRepository;
use App\AudioToText\Infrastructure\DbTranscriptionJobRepository;
use App\AudioToText\Infrastructure\Process\ProcessRunner;
use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\AudioToTextSettingsFactory;
use App\Tests\Support\Fake\AudioToText\FailingJobRepository;
use App\Tests\Support\IntegrationDb;
use Closure;
use Codeception\Test\Unit;
use HttpSoft\Message\Stream;
use HttpSoft\Message\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

use function bin2hex;
use function fopen;
use function fwrite;
use function glob;
use function is_dir;
use function pack;
use function random_bytes;
use function random_int;
use function rewind;
use function rmdir;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function unlink;

use const UPLOAD_ERR_OK;

/**
 * Conversations against real MySQL, and the paired enqueue that creates them.
 *
 * The guarantee under test is a *database* one — a Customer child and an Agent child are written in one
 * transaction or not at all — so a test double proving the code calls the right methods would prove
 * nothing. Real ffprobe runs too, on a quarter-second silent WAV this test generates: it is the actual
 * path an upload takes, and faking it would skip the only step that can reject a file for its length.
 *
 * **This test never calls `claimNextQueued()`.** Every row it writes it also removes, and it touches no
 * row it did not create, so it is safe to run beside real administrator work sitting in the queue.
 */
final class AudioConversationTest extends Unit
{
    private ConnectionInterface $connection;
    private DbAudioConversationRepository $conversations;
    private DbTranscriptionJobRepository $jobs;
    private int $adminId;
    private string $temporaryDirectory;

    /** Any store id: this project has no foreign key to the store mirror, by design. */
    private int $storeSourceId;

    /** @var list<string> */
    private array $createdUsernames = [];

    /** @var list<string> */
    private array $createdConversationIds = [];

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->conversations = new DbAudioConversationRepository($this->connection);
        $this->jobs = new DbTranscriptionJobRepository($this->connection, new SystemClock());

        $username = 'a2t-conv-' . bin2hex(random_bytes(6));
        (new DbAdminUserRepository($this->connection, new SystemClock()))->create($username, 'x');
        $this->createdUsernames[] = $username;

        /** @var array<string, mixed> $row */
        $row = $this->connection
            ->createCommand('SELECT id FROM {{%admin_users}} WHERE username = :u', [':u' => $username])
            ->queryOne();
        $this->adminId = (int) $row['id'];

        // Well outside anything the store mirror holds, so nothing this test writes can be mistaken
        // for a real store's history.
        $this->storeSourceId = 900_000_000 + random_int(1, 999_999);

        $this->temporaryDirectory = sys_get_temp_dir() . '/a2t-conv-' . bin2hex(random_bytes(6));
    }

    protected function _after(): void
    {
        // Children first: the conversation foreign key is RESTRICT.
        $this->connection->createCommand(
            'DELETE j FROM {{%audio_transcription_jobs}} j
             JOIN {{%audio_conversations}} c ON c.id = j.conversation_id
             WHERE c.store_source_id = :s',
            [':s' => $this->storeSourceId],
        )->execute();

        IntegrationDb::cleanup($this->connection, '{{%audio_conversations}}', ['store_source_id' => $this->storeSourceId]);

        foreach ($this->createdConversationIds as $publicId) {
            IntegrationDb::cleanup($this->connection, '{{%audio_conversations}}', ['public_id' => $publicId]);
        }

        foreach ($this->createdUsernames as $username) {
            IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => $username]);
        }

        $this->removeDirectory($this->temporaryDirectory);

        $this->createdUsernames = [];
        $this->createdConversationIds = [];
    }

    // ------------------------------------------------------------------------ the paired enqueue

    public function testACommonUploadCreatesOneConversationWithOneChild(): void
    {
        $publicId = $this->queue()->enqueueConversation(
            ConversationMode::Common,
            $this->storeSourceId,
            [SourceRole::Common->value => $this->wavUpload('mixed.wav')],
            $this->adminId,
        );

        $conversation = $this->conversations->findByPublicId($publicId);

        self::assertNotNull($conversation);
        self::assertSame(ConversationMode::Common, $conversation->mode);
        self::assertTrue($conversation->hasValidShape());
        self::assertCount(1, $conversation->children);
        self::assertSame(SourceRole::Common, $conversation->children[0]->sourceRole);
        self::assertSame(JobStatus::QUEUED, $conversation->children[0]->status);
    }

    public function testASeparateUploadCreatesOneConversationWithBothRoles(): void
    {
        $publicId = $this->queue()->enqueueConversation(
            ConversationMode::Separate,
            $this->storeSourceId,
            [
                SourceRole::Customer->value => $this->wavUpload('customer.wav'),
                SourceRole::Agent->value => $this->wavUpload('agent.wav'),
            ],
            $this->adminId,
        );

        $conversation = $this->conversations->findByPublicId($publicId);

        self::assertNotNull($conversation);
        self::assertSame(ConversationMode::Separate, $conversation->mode);
        self::assertTrue($conversation->hasValidShape());
        self::assertCount(2, $conversation->children);
        self::assertSame('customer.wav', $conversation->childFor(SourceRole::Customer)?->originalFilename);
        self::assertSame('agent.wav', $conversation->childFor(SourceRole::Agent)?->originalFilename);
    }

    /**
     * The whole reason parent and children share a transaction.
     *
     * A failure while writing the second child must leave nothing: no conversation promising two
     * recordings and holding one, and no stored file that no row owns.
     */
    public function testAFailureWritingTheSecondChildLeavesNothingBehind(): void
    {
        $before = $this->conversations->countForStore($this->storeSourceId);

        try {
            $this->queue(failAfterChildren: 1)->enqueueConversation(
                ConversationMode::Separate,
                $this->storeSourceId,
                [
                    SourceRole::Customer->value => $this->wavUpload('customer.wav'),
                    SourceRole::Agent->value => $this->wavUpload('agent.wav'),
                ],
                $this->adminId,
            );

            self::fail('The enqueue should have failed while writing the second child.');
        } catch (Throwable $e) {
            // Whatever the storage layer threw, unchanged. The queue's own catch removes the files it
            // wrote and re-throws; it deliberately does not translate a database failure into an
            // uploader-facing message it would have to invent.
            self::assertStringContainsString('Simulated failure', $e->getMessage());
        }

        self::assertSame(
            $before,
            $this->conversations->countForStore($this->storeSourceId),
            'A half-written pair must roll back to no conversation at all.',
        );
        self::assertSame(0, $this->jobsForStore(), 'No child may survive a rolled-back pair.');
        self::assertSame(
            [],
            glob($this->temporaryDirectory . '/jobs/*') ?: [],
            'Every stored recording must be removed when the pair is rejected.',
        );
    }

    /**
     * A cap with room for one refuses a pair whole.
     *
     * Asking per child would let the Customer take the last slot and then reject the Agent, which is
     * the one outcome the design forbids: an upload that is half accepted.
     */
    public function testAQueueWithRoomForOneRejectsAPairWithoutWritingEither(): void
    {
        // One slot in the whole installation, and this test's own COMMON upload takes it.
        $queue = $this->queue(maxQueue: $this->activeJobCount() + 1);

        $queue->enqueueConversation(
            ConversationMode::Common,
            $this->storeSourceId,
            [SourceRole::Common->value => $this->wavUpload('first.wav')],
            $this->adminId,
        );

        $before = $this->conversations->countForStore($this->storeSourceId);

        try {
            $queue->enqueueConversation(
                ConversationMode::Separate,
                $this->storeSourceId,
                [
                    SourceRole::Customer->value => $this->wavUpload('customer.wav'),
                    SourceRole::Agent->value => $this->wavUpload('agent.wav'),
                ],
                $this->adminId,
            );

            self::fail('A pair needing two slots must not fit in a queue with room for one.');
        } catch (AudioTranscriptionException) {
            // Expected.
        }

        self::assertSame($before, $this->conversations->countForStore($this->storeSourceId));
        self::assertSame(1, $this->jobsForStore(), 'Only the first upload may exist.');
    }

    // ------------------------------------------------------------------------- the store history

    /**
     * A separate upload is **one** entry in a store's history, not two.
     *
     * This is the difference between the two repositories: the job repository legitimately sees two
     * rows for the same upload, and every store-facing count must not.
     */
    public function testAPairCountsAsOneConversationButTwoJobs(): void
    {
        $this->queue()->enqueueConversation(
            ConversationMode::Separate,
            $this->storeSourceId,
            [
                SourceRole::Customer->value => $this->wavUpload('customer.wav'),
                SourceRole::Agent->value => $this->wavUpload('agent.wav'),
            ],
            $this->adminId,
        );

        self::assertSame(1, $this->conversations->countForStore($this->storeSourceId));
        self::assertCount(1, $this->conversations->forStore($this->storeSourceId, 20));
        self::assertSame(2, $this->jobsForStore());
    }

    public function testAStoresHistoryHoldsOnlyItsOwnUploads(): void
    {
        $otherStore = $this->storeSourceId + 1;

        $this->queue()->enqueueConversation(
            ConversationMode::Common,
            $this->storeSourceId,
            [SourceRole::Common->value => $this->wavUpload('mine.wav')],
            $this->adminId,
        );

        self::assertSame(1, $this->conversations->countForStore($this->storeSourceId));
        self::assertSame(0, $this->conversations->countForStore($otherStore));
        self::assertSame([], $this->conversations->forStore($otherStore, 20));
    }

    public function testTheHistoryIsNewestFirstAndPages(): void
    {
        foreach (['one.wav', 'two.wav', 'three.wav'] as $filename) {
            $this->queue()->enqueueConversation(
                ConversationMode::Common,
                $this->storeSourceId,
                [SourceRole::Common->value => $this->wavUpload($filename)],
                $this->adminId,
            );
        }

        $firstPage = $this->conversations->forStore($this->storeSourceId, 2);
        $secondPage = $this->conversations->forStore($this->storeSourceId, 2, 2);

        self::assertCount(2, $firstPage);
        self::assertCount(1, $secondPage);
        self::assertSame('three.wav', $firstPage[0]->children[0]->originalFilename);
        self::assertSame('one.wav', $secondPage[0]->children[0]->originalFilename);
    }

    // ---------------------------------------------------------------------------- the purge sweep

    /**
     * Retention deletes expired jobs one at a time, and the two children of a pair can fall in
     * different passes — so the sweep must never remove a parent that still has a child.
     */
    public function testTheSweepRemovesAParentOnlyOnceEveryChildIsGone(): void
    {
        $publicId = $this->queue()->enqueueConversation(
            ConversationMode::Separate,
            $this->storeSourceId,
            [
                SourceRole::Customer->value => $this->wavUpload('customer.wav'),
                SourceRole::Agent->value => $this->wavUpload('agent.wav'),
            ],
            $this->adminId,
        );

        $conversation = $this->conversations->findByPublicId($publicId);
        self::assertNotNull($conversation);

        // One child purged, as one retention pass would do.
        $first = $this->jobs->findByPublicId($conversation->children[0]->publicId);
        self::assertNotNull($first);
        $this->jobs->delete($first->id);

        $this->conversations->deleteChildless();

        self::assertNotNull(
            $this->conversations->findByPublicId($publicId),
            'A conversation with a surviving child must not be swept.',
        );

        // The second child goes in a later pass.
        $second = $this->jobs->findByPublicId($conversation->children[1]->publicId);
        self::assertNotNull($second);
        $this->jobs->delete($second->id);

        self::assertSame(1, $this->conversations->deleteChildless());
        self::assertNull(
            $this->conversations->findByPublicId($publicId),
            'A conversation with no children left must be swept.',
        );
    }

    // ---------------------------------------------------------------------------------- helpers

    private function queue(int $maxQueue = 0, ?int $failAfterChildren = null): TranscriptionQueue
    {
        $settings = $this->settings($maxQueue);
        $jobs = $this->jobs;

        return new TranscriptionQueue(
            $failAfterChildren === null ? $jobs : new FailingJobRepository($jobs, $failAfterChildren),
            $this->conversations,
            new QueuedAudioStorage($settings),
            new AudioDurationProbe($settings, new ProcessRunner($settings)),
            $settings,
            new SystemClock(),
            $this->transactions(),
        );
    }

    private function settings(int $maxQueue = 0): AudioToTextSettings
    {
        return AudioToTextSettingsFactory::create(
            temporaryDirectory: $this->temporaryDirectory,
            maxQueue: $maxQueue,
        );
    }

    /** The real thing: these tests are about what survives a rollback. */
    private function transactions(): TransactionRunnerInterface
    {
        $connection = $this->connection;

        return new class ($connection) implements TransactionRunnerInterface {
            public function __construct(private readonly ConnectionInterface $connection) {}

            public function run(Closure $work): mixed
            {
                return $this->connection->transaction($work);
            }
        };
    }

    private function activeJobCount(): int
    {
        return $this->jobs->countActive();
    }

    private function jobsForStore(): int
    {
        /** @var array<string, mixed> $row */
        $row = $this->connection->createCommand(
            'SELECT COUNT(*) c FROM {{%audio_transcription_jobs}} j
             JOIN {{%audio_conversations}} c ON c.id = j.conversation_id
             WHERE c.store_source_id = :s',
            [':s' => $this->storeSourceId],
        )->queryOne();

        return (int) $row['c'];
    }

    /**
     * A quarter-second of silence, 8 kHz mono 16-bit.
     *
     * Real PCM rather than a bare header, because real ffprobe reads it and a header claiming zero
     * data bytes has no duration to report.
     */
    private function wavUpload(string $filename): UploadedFileInterface
    {
        $samples = 2000;
        $data = str_repeat("\0\0", $samples);
        $bytes = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('V', 16)
            . pack('v', 1) . pack('v', 1) . pack('V', 8000) . pack('V', 16000)
            . pack('v', 2) . pack('v', 16) . 'data' . pack('V', strlen($data)) . $data;

        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $bytes);
        rewind($resource);

        return new UploadedFile(
            new Stream($resource),
            strlen($bytes),
            UPLOAD_ERR_OK,
            $filename,
            'audio/wav',
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDirectory($entry) : @unlink($entry);
        }

        @rmdir($directory);
    }
}
