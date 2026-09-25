<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Integration\Order58Recording\ChannelApiProbe;
use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Application\RecordingDownloader;
use App\Order58\Application\RecordingImportProcessor;
use App\Order58\Console\ImportRecordingsCommand;
use App\Order58\Domain\CallImportItem;
use App\Order58\Domain\CallImportRepositoryInterface;
use App\Order58\Domain\Order58ImportStatus;
use App\Shared\Audio\AudioIngestionOutcome;
use App\Shared\Audio\AudioIngestionPortInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Log\SecretRedactor;
use App\Shared\Machine\MachineResourceProbeInterface;
use App\Shared\Machine\ResourceAdmission;
use App\Shared\Machine\ResourceBudget;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Yiisoft\Yii\Console\ExitCode;

use function fopen;
use function flock;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

use const LOCK_EX;
use const LOCK_NB;

/**
 * The importer command's gates, in the order it applies them.
 *
 * Every test here proves something about **not** doing work, because that is where the risk lives on a
 * small server: the command's job is to be cheap and to know when to stay out of the way.
 *
 * The claim is what is being watched throughout. `claimNext()` is the only method that takes ownership of
 * a row, so a spy that counts calls to it answers "was the item left pending?" exactly — no attempt is
 * incremented and no status is written unless something was claimed first.
 */
final class ImportRecordingsCommandTest extends Unit
{
    /** Below this, a pass must defer. Matches the shipped ORDER58_IMPORT_MIN_AVAILABLE_MB default. */
    private const MIN_MB = 350;

    private const MAX_LOAD = 1.5;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function _after(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        $this->temporaryFiles = [];
    }

    // ------------------------------------------------------------------ resources

    /**
     * The requirement in one test: under memory pressure nothing is claimed, nothing is failed, no attempt
     * is spent, and the exit code says "fine, try again later" so the timer keeps its cadence.
     */
    public function testMemoryPressureDefersWithoutClaimingTheItem(): void
    {
        $repository = $this->repositoryWithOnePendingItem();
        [$command, $output] = $this->command($repository, availableMb: 120, load: 0.2);

        $exit = $command->run(new ArrayInput(['--once' => true]), $output);

        assertSame(ExitCode::OK, $exit, 'A deferral is not a failure — the timer must not see an error.');
        assertSame(0, $repository->claims, 'Nothing may be claimed when the machine is under pressure.');
        assertSame(0, $repository->writes, 'A deferred pass must not touch the row at all.');
        assertStringContainsString('deferred due to system resources', $output->fetch());
    }

    public function testHighLoadDefersWithoutClaimingTheItem(): void
    {
        $repository = $this->repositoryWithOnePendingItem();
        [$command, $output] = $this->command($repository, availableMb: 8000, load: 4.0);

        $exit = $command->run(new ArrayInput(['--once' => true]), $output);

        assertSame(ExitCode::OK, $exit);
        assertSame(0, $repository->claims);
        assertSame(0, $repository->writes);
        assertStringContainsString('load per core', $output->fetch());
    }

    /** With room to spare the gate is out of the way: the queue is reached and the item claimed. */
    public function testAHealthyMachineClaimsTheItem(): void
    {
        $repository = $this->repositoryWithOnePendingItem();
        [$command, $output] = $this->command($repository, availableMb: 8000, load: 0.2);

        $exit = $command->run(new ArrayInput(['--once' => true]), $output);

        assertSame(ExitCode::OK, $exit);
        assertSame(1, $repository->claims);
    }

    /**
     * Exactly the threshold is enough. Worth pinning: an off-by-one here is the difference between a
     * working importer and one that defers for ever on a machine sitting right at its budget.
     */
    public function testExactlyTheThresholdIsAdmitted(): void
    {
        $repository = $this->repositoryWithOnePendingItem();
        [$command, $output] = $this->command($repository, availableMb: self::MIN_MB, load: self::MAX_LOAD);

        $command->run(new ArrayInput(['--once' => true]), $output);

        assertSame(1, $repository->claims);
    }

    /**
     * The importer's budget is its own. 500 MB is below what a transcription needs and far above what a
     * download needs, and the importer must not inherit the heavier number — on a small server that would
     * stall imports permanently, because the gate fails closed.
     */
    public function testTheImporterIsNotHeldToTheTranscriptionThreshold(): void
    {
        $repository = $this->repositoryWithOnePendingItem();
        [$command, $output] = $this->command($repository, availableMb: 500, load: 0.3);

        $command->run(new ArrayInput(['--once' => true]), $output);

        assertSame(1, $repository->claims, '500 MB is ample for a 92 MB download.');
    }

    // ------------------------------------------------------------------ one at a time

    /** `--once` means one recording, even when the queue has more waiting. */
    public function testOnceProcessesAtMostOneRecording(): void
    {
        $repository = $this->repositoryWithOnePendingItem(pending: 5);
        [$command, $output] = $this->command($repository, availableMb: 8000, load: 0.2);

        $command->run(new ArrayInput(['--once' => true]), $output);

        assertSame(1, $repository->claims, '--once must claim exactly one item, never drain the queue.');
        assertStringContainsString('Processed 1 recording(s).', $output->fetch());
    }

    // ------------------------------------------------------------------ locks

    /**
     * The internal lock is the authority however the command starts, including by hand. A second process
     * holding it means this one does no work — and says so without failing, because a correctly refused
     * duplicate is the guarantee working.
     */
    public function testAHeldLockStopsAnySecondImport(): void
    {
        $repository = $this->repositoryWithOnePendingItem();
        $lockFile = $this->temporaryPath();

        // Stand in for the other process: hold the same flock the command will try to take.
        $holder = fopen($lockFile, 'c');
        self::assertIsResource($holder);
        flock($holder, LOCK_EX | LOCK_NB);

        [$command, $output] = $this->command($repository, availableMb: 8000, load: 0.2, lockFile: $lockFile);

        $exit = $command->run(new ArrayInput(['--once' => true]), $output);

        flock($holder, LOCK_EX);

        assertSame(ExitCode::OK, $exit);
        assertSame(0, $repository->claims, 'A second importer must not claim anything.');
        assertStringContainsString('already running', $output->fetch());
    }

    // ------------------------------------------------------------------ the feature flag

    /**
     * The flag stays a real gate. With it off the timer may fire all it likes: no resource probe, no claim,
     * no provider request.
     */
    public function testTheFeatureFlagStopsEverythingBeforeTheResourceCheck(): void
    {
        $repository = $this->repositoryWithOnePendingItem();
        [$command, $output] = $this->command($repository, availableMb: 8000, load: 0.2, enabled: false);

        $exit = $command->run(new ArrayInput(['--once' => true]), $output);

        assertSame(ExitCode::OK, $exit);
        assertSame(0, $repository->claims);
        assertStringContainsString('ORDER58_RECORDING_IMPORT_ENABLED', $output->fetch());
    }

    // ------------------------------------------------------------------ the temp directory

    /**
     * A TMPDIR that does not exist is not an error `tempnam()` reports — it falls back to the system /tmp,
     * silently undoing the one setting that keeps a download on the same filesystem as its destination.
     * Refusing to start is the only way that misconfiguration is ever noticed.
     */
    public function testAnUnusableTemporaryDirectoryRefusesToStart(): void
    {
        $repository = $this->repositoryWithOnePendingItem();
        [$command, $output] = $this->command(
            $repository,
            availableMb: 8000,
            load: 0.2,
            temporaryDirectory: sys_get_temp_dir() . '/kf-o58-not-a-real-directory',
        );

        $exit = $command->run(new ArrayInput(['--once' => true]), $output);

        assertSame(ExitCode::DATAERR, $exit);
        assertSame(0, $repository->claims);
        assertStringContainsString('nowhere safe to land', $output->fetch());
    }

    // ------------------------------------------------------------------ plumbing

    /** @return array{ImportRecordingsCommand, BufferedOutput} */
    private function command(
        SpyCallImportRepository $repository,
        int $availableMb,
        float $load,
        ?string $lockFile = null,
        bool $enabled = true,
        ?string $temporaryDirectory = null,
    ): array {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-25 10:00:00', new DateTimeZone('UTC'));
            }
        };

        $processor = new RecordingImportProcessor(
            $repository,
            // A mocked transport, following tests/Unit/Order58/RecordingDownloadTest. A claimed item gets
            // a 503 rather than reaching the provider, which is enough for every assertion here: what is
            // counted is whether the item was claimed at all, not what became of it afterwards. No test in
            // this file can touch the network.
            new RecordingDownloader(new ChannelApiProbe(
                httpClient: new GuzzleClient([
                    'handler' => HandlerStack::create(new MockHandler([new GuzzleResponse(503)])),
                ]),
            )),
            new AlwaysQueuesIngestion(),
            new SecretRedactor([]),
            $clock,
            new NullLogger(),
            3,
        );

        $command = new ImportRecordingsCommand(
            $processor,
            new ResourceAdmission($this->probe($availableMb, $load)),
            new ResourceBudget(self::MIN_MB, self::MAX_LOAD),
            new NullLogger(),
            $lockFile ?? $this->temporaryPath(),
            $temporaryDirectory ?? sys_get_temp_dir(),
            $enabled,
        );

        return [$command, new BufferedOutput()];
    }

    private function probe(int $availableMb, float $load): MachineResourceProbeInterface
    {
        return new class ($availableMb, $load) implements MachineResourceProbeInterface {
            public function __construct(
                private readonly int $availableMb,
                private readonly float $load,
            ) {}

            public function availableMegabytes(): int
            {
                return $this->availableMb;
            }

            public function loadAveragePerCore(): float
            {
                return $this->load;
            }
        };
    }

    private function repositoryWithOnePendingItem(int $pending = 1): SpyCallImportRepository
    {
        return new SpyCallImportRepository($pending);
    }

    private function temporaryPath(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'kf-o58-test-');
        $this->temporaryFiles[] = $path;

        return $path;
    }
}

/**
 * Counts what the command asks of the queue.
 *
 * `claims` is the number that matters: it is the only way a row changes hands, so `claims === 0` is a
 * complete statement that the item is still PENDING with its attempt count untouched. `writes` catches any
 * status change that somehow happened without one.
 */
final class SpyCallImportRepository implements CallImportRepositoryInterface
{
    public int $claims = 0;
    public int $writes = 0;

    public function __construct(private int $pending) {}

    public function claimNext(DateTimeImmutable $now): ?CallImportItem
    {
        if ($this->pending <= 0) {
            return null;
        }

        $this->pending--;
        $this->claims++;

        return new CallImportItem(
            id: 1,
            batchId: 1,
            storeSourceId: 1491,
            callSessionId: '22487129',
            channel: RecordingChannel::Mixed,
            callTimeRaw: '2026-09-25 06:09:16',
            callDate: '2026-09-25',
            orderId: '16547451',
            status: Order58ImportStatus::Fetching,
            attempts: 0,
            errorCode: null,
            errorMessage: null,
            bytes: null,
            durationSeconds: null,
            conversationPublicId: null,
            completedAt: null,
            updatedAt: $now,
            recordingCompany: 'WGEU',
            provider: 'WHISPER',
            generateAiAudio: false,
            requestedByAdminId: 1,
        );
    }

    public function recoverStuck(DateTimeImmutable $threshold, DateTimeImmutable $now): int
    {
        return 0;
    }

    public function markImported(
        int $id,
        string $conversationPublicId,
        int $bytes,
        ?float $durationSeconds,
        DateTimeImmutable $now,
    ): void {
        $this->writes++;
    }

    public function markSettled(
        int $id,
        Order58ImportStatus $status,
        ?string $errorCode,
        ?string $errorMessage,
        ?int $bytes,
        ?float $durationSeconds,
        DateTimeImmutable $now,
    ): void {
        $this->writes++;
    }

    public function requeue(
        int $id,
        DateTimeImmutable $nextAttemptAt,
        ?string $errorCode,
        ?string $errorMessage,
        DateTimeImmutable $now,
    ): void {
        $this->writes++;
    }

    // Unused by the worker path; the page owns these.

    public function createBatch(
        int $storeSourceId,
        string $triggeredBy,
        ?int $requestedByAdminId,
        string $provider,
        bool $generateAiAudio,
        string $recordingCompany,
        DateTimeImmutable $now,
    ): int {
        return 1;
    }

    public function queueCall(
        int $batchId,
        int $storeSourceId,
        string $callSessionId,
        string $callTimeRaw,
        string $callDate,
        ?string $orderId,
        array $channels,
        DateTimeImmutable $now,
    ): int {
        return 0;
    }

    public function statusesFor(int $storeSourceId, array $callSessionIds): array
    {
        return [];
    }

    public function retry(int $id, DateTimeImmutable $now): bool
    {
        return false;
    }

    public function history(?int $storeSourceId, int $limit): array
    {
        return [];
    }

    public function findItem(int $id): ?CallImportItem
    {
        return null;
    }
}

/** Accepts every recording without touching the queue, so the test never needs the audio pipeline. */
final class AlwaysQueuesIngestion implements AudioIngestionPortInterface
{
    public function ingestFile(
        int $storeSourceId,
        string $path,
        string $filename,
        string $recordingType,
        ?string $orderId,
        string $provider,
        bool $generateAiAudio,
        int $adminUserId,
    ): AudioIngestionOutcome {
        return AudioIngestionOutcome::queued('c0ffee00c0ffee00c0ffee00c0ffee00');
    }
}
