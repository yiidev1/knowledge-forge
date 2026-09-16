<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioToText;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\QueuedAudioStorage;
use App\AudioToText\Application\TranscriptionQueue;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\TranscriptionProvider;
use App\AudioToText\Infrastructure\AudioDurationProbe;
use App\AudioToText\Infrastructure\DbAudioConversationRepository;
use App\AudioToText\Infrastructure\DbAudioToTextSettingsRepository;
use App\AudioToText\Infrastructure\DbTranscriptionJobRepository;
use App\AudioToText\Infrastructure\Process\ProcessRunner;
use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\AudioToTextSettingsFactory;
use App\Tests\Support\IntegrationDb;
use Closure;
use Codeception\Test\Unit;
use HttpSoft\Message\Stream;
use HttpSoft\Message\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;
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
 * The provider is written onto the job at enqueue, against real MySQL.
 *
 * The guarantee is a *database* one — the column, the CHECK constraint, and the fact that both children
 * of a paired upload carry the same value — so a doubled repository would prove nothing. Real ffprobe
 * runs on a generated quarter-second silent WAV, as in {@see AudioConversationTest}, because that is
 * the path a real upload takes.
 *
 * **This test never calls `claimNextQueued()`.** Every row it writes it also removes, and it touches no
 * row it did not create, so it is safe to run beside real administrator work sitting in the queue. The
 * settings row is the one exception — there is only ever one — so its value is captured and restored.
 */
final class TranscriptionProviderPersistenceTest extends Unit
{
    private ConnectionInterface $connection;
    private DbAudioConversationRepository $conversations;
    private DbTranscriptionJobRepository $jobs;
    private DbAudioToTextSettingsRepository $settingsRepository;
    private int $adminId;
    private string $temporaryDirectory;
    private int $storeSourceId;

    /** Restored in teardown: the settings table holds exactly one row, shared with the real system. */
    private TranscriptionProvider $originalDefault = TranscriptionProvider::Whisper;

    /** @var list<string> */
    private array $createdUsernames = [];

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->conversations = new DbAudioConversationRepository($this->connection);
        $this->jobs = new DbTranscriptionJobRepository($this->connection, new SystemClock());
        $this->settingsRepository = new DbAudioToTextSettingsRepository($this->connection, new SystemClock());

        $this->originalDefault = $this->settingsRepository->defaultProvider();

        $username = 'a2t-prov-' . bin2hex(random_bytes(6));
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

        $this->temporaryDirectory = sys_get_temp_dir() . '/a2t-prov-' . bin2hex(random_bytes(6));
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

        // Put the shared setting back before the admin row it may reference is deleted.
        $this->connection->createCommand(
            'UPDATE {{%audio_to_text_settings}}
             SET `default_transcription_provider` = :p, `updated_by_admin_id` = NULL
             WHERE `id` = 1',
            [':p' => $this->originalDefault->value],
        )->execute();

        foreach ($this->createdUsernames as $username) {
            IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => $username]);
        }

        $this->removeDirectory($this->temporaryDirectory);
        $this->createdUsernames = [];
    }

    // ------------------------------------------------------------------ enqueue writes the provider

    public function testTheChosenProviderIsStoredOnTheJob(): void
    {
        $publicId = $this->enqueueCommon(TranscriptionProvider::Deepgram);

        $this->assertSame(['DEEPGRAM'], $this->storedProviders($publicId));
    }

    public function testWhisperIsStoredExplicitlyRatherThanAsNull(): void
    {
        $publicId = $this->enqueueCommon(TranscriptionProvider::Whisper);

        $this->assertSame(
            ['WHISPER'],
            $this->storedProviders($publicId),
            'NULL means "queued before providers existed"; a deliberate choice is recorded as itself.',
        );
    }

    /**
     * A SEPARATE upload is one call recorded twice. Transcribing its halves with different engines
     * would make the customer and agent sides incomparable for no gain, so the provider is threaded to
     * every child from one place.
     */
    public function testBothChildrenOfAPairShareOneProvider(): void
    {
        $publicId = $this->queue()->enqueueConversation(
            ConversationMode::Separate,
            $this->storeSourceId,
            [
                SourceRole::Customer->value => $this->wavUpload('customer.wav'),
                SourceRole::Agent->value => $this->wavUpload('agent.wav'),
            ],
            $this->adminId,
            TranscriptionProvider::Deepgram,
        );

        $this->assertSame(['DEEPGRAM', 'DEEPGRAM'], $this->storedProviders($publicId));
    }

    /** Read back through the repository, so the hydration path is covered and not just the column. */
    public function testTheStoredProviderSurvivesHydration(): void
    {
        $publicId = $this->enqueueCommon(TranscriptionProvider::Deepgram);
        $children = $this->conversations->findByPublicId($publicId)?->children ?? [];

        $this->assertCount(1, $children);
        $this->assertSame(TranscriptionProvider::Deepgram, $children[0]->transcriptionProvider);
    }

    // ------------------------------------------------------------------ the default is not a policy

    /**
     * **The historical-integrity assertion.** Changing the default never rewrites a queued job.
     *
     * The default answers "what will the next upload use?". If the worker read it instead of the job's
     * own column, a Whisper transcript would start claiming Deepgram the moment an administrator
     * flipped a radio button.
     */
    public function testChangingTheGlobalDefaultLeavesQueuedJobsAlone(): void
    {
        $publicId = $this->enqueueCommon(TranscriptionProvider::Whisper);

        $this->settingsRepository->saveDefaultProvider(TranscriptionProvider::Deepgram, $this->adminId);

        $this->assertSame(TranscriptionProvider::Deepgram, $this->settingsRepository->defaultProvider());
        $this->assertSame(
            ['WHISPER'],
            $this->storedProviders($publicId),
            'A queued job keeps the provider it was queued with.',
        );

        $job = $this->jobs->findByPublicId($this->firstChildPublicId($publicId));
        $this->assertSame(TranscriptionProvider::Whisper, $job?->transcriptionProvider());
    }

    public function testTheDefaultRoundTripsThroughTheDatabase(): void
    {
        $this->settingsRepository->saveDefaultProvider(TranscriptionProvider::Deepgram, $this->adminId);
        $this->assertSame(TranscriptionProvider::Deepgram, $this->settingsRepository->defaultProvider());

        $this->settingsRepository->saveDefaultProvider(TranscriptionProvider::Whisper, $this->adminId);
        $this->assertSame(TranscriptionProvider::Whisper, $this->settingsRepository->defaultProvider());
    }

    /** `CHECK (id = 1)` plus an upsert: saving twice must update, never accumulate rows. */
    public function testTheSettingsTableKeepsExactlyOneRow(): void
    {
        $this->settingsRepository->saveDefaultProvider(TranscriptionProvider::Deepgram, $this->adminId);
        $this->settingsRepository->saveDefaultProvider(TranscriptionProvider::Whisper, $this->adminId);

        $count = (int) $this->connection
            ->createCommand('SELECT COUNT(*) FROM {{%audio_to_text_settings}}')
            ->queryScalar();

        $this->assertSame(1, $count);
    }

    /** The database is the last line of defence against a provider the enum no longer allows. */
    public function testTheDatabaseRefusesAnUnknownProvider(): void
    {
        $this->expectExceptionMessageMatches('/CHECK|constraint/i');

        $this->connection->createCommand(
            'UPDATE {{%audio_to_text_settings}} SET `default_transcription_provider` = :p WHERE `id` = 1',
            [':p' => 'GOOGLE'],
        )->execute();
    }

    // ---------------------------------------------------------------------------------- helpers

    private function enqueueCommon(TranscriptionProvider $provider): string
    {
        return $this->queue()->enqueueConversation(
            ConversationMode::Common,
            $this->storeSourceId,
            [SourceRole::Common->value => $this->wavUpload('mixed.wav')],
            $this->adminId,
            $provider,
        );
    }

    /**
     * The raw column values for one conversation's children, in creation order.
     *
     * Read with SQL rather than through the repository on purpose: a NULL that the hydrator would have
     * turned into Whisper has to stay visible here.
     *
     * @return list<string|null>
     */
    private function storedProviders(string $conversationPublicId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->createCommand(
            'SELECT j.transcription_provider AS p
             FROM {{%audio_transcription_jobs}} j
             JOIN {{%audio_conversations}} c ON c.id = j.conversation_id
             WHERE c.public_id = :id
             ORDER BY j.id ASC',
            [':id' => $conversationPublicId],
        )->queryAll();

        $providers = [];
        foreach ($rows as $row) {
            $providers[] = $row['p'] === null ? null : (string) $row['p'];
        }

        return $providers;
    }

    private function firstChildPublicId(string $conversationPublicId): string
    {
        return (string) $this->connection->createCommand(
            'SELECT j.public_id
             FROM {{%audio_transcription_jobs}} j
             JOIN {{%audio_conversations}} c ON c.id = j.conversation_id
             WHERE c.public_id = :id
             ORDER BY j.id ASC
             LIMIT 1',
            [':id' => $conversationPublicId],
        )->queryScalar();
    }

    private function queue(): TranscriptionQueue
    {
        $settings = $this->settings();

        return new TranscriptionQueue(
            $this->jobs,
            $this->conversations,
            new QueuedAudioStorage($settings),
            new AudioDurationProbe($settings, new ProcessRunner($settings)),
            $settings,
            new SystemClock(),
            $this->transactions(),
        );
    }

    private function settings(): AudioToTextSettings
    {
        return AudioToTextSettingsFactory::create(temporaryDirectory: $this->temporaryDirectory);
    }

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

    /** A quarter-second of silence: real ffprobe has to be able to read a duration out of it. */
    private function wavUpload(string $filename): UploadedFileInterface
    {
        $samples = 4000;
        $data = str_repeat(pack('v', 0), $samples);
        $bytes = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16)
            . 'data' . pack('V', strlen($data)) . $data;

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $bytes);
        rewind($handle);

        return new UploadedFile(new Stream($handle), strlen($bytes), UPLOAD_ERR_OK, $filename, 'audio/wav');
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach ((array) glob($path . '/*') as $entry) {
            $entry = (string) $entry;
            is_dir($entry) ? $this->removeDirectory($entry) : @unlink($entry);
        }

        @rmdir($path);
    }
}
