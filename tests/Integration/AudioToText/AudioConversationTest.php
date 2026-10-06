<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioToText;

use App\AudioToText\Application\AudioIngestionService;
use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\AudioUploadValidator;
use App\AudioToText\Application\QueuedAudioStorage;
use App\AudioToText\Application\TranscriptionQueue;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\TranscriptionProvider;
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
use function file_put_contents;
use function glob;
use function is_dir;
use function pack;
use function random_bytes;
use function random_int;
use function rewind;
use function rmdir;
use function str_repeat;
use function strtolower;
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

    /** A mixed recording of one call, enqueued the way the importer would. */
    // ---------------------------------------------------------------------------------------------
    // Which recording is a side of this call, once that side has been replaced
    // ---------------------------------------------------------------------------------------------

    /**
     * Replacing one side leaves exactly one current recording for it, and the other side alone.
     *
     * The bug this pins: Update Audio writes a NEW conversation and job for the side and leaves the old
     * one standing, which is what makes a version history possible. Asked which recording was the
     * Customer side, this returned both — and the combined reader, having no basis to choose, refused
     * the whole call as AmbiguousChildren. An ordinary replacement took the conversation away.
     */
    public function testReplacingOneSideLeavesOneCurrentRecordingForIt(): void
    {
        $session = 'session' . random_int(100000, 999999);
        $firstCaller = $this->channelFor($session, RecordingType::Caller, SourceRole::Customer);
        $callee = $this->channelFor($session, RecordingType::Callee, SourceRole::Agent);

        $this->complete($this->jobIdOf($firstCaller));
        $this->complete($this->jobIdOf($callee));

        $ids = $this->conversations->channelJobIdsForCallSession($this->storeSourceId, $session);
        self::assertSame([$this->jobIdOf($firstCaller)], $ids[RecordingType::Caller->value]);
        self::assertSame([$this->jobIdOf($callee)], $ids[RecordingType::Callee->value]);

        // The replacement, finished. It is the Customer side from now on.
        $replacement = $this->channelFor($session, RecordingType::Caller, SourceRole::Customer);
        $this->complete($this->jobIdOf($replacement));

        $ids = $this->conversations->channelJobIdsForCallSession($this->storeSourceId, $session);

        self::assertCount(1, $ids[RecordingType::Caller->value], 'One Customer side, not two.');
        self::assertSame([$this->jobIdOf($replacement)], $ids[RecordingType::Caller->value]);
        self::assertSame(
            [$this->jobIdOf($callee)],
            $ids[RecordingType::Callee->value],
            'Replacing the Customer side left the Agent side exactly as it was.',
        );
    }

    /** And the same the other way round, because neither side is special. */
    public function testReplacingTheAgentSideLeavesTheCustomerSideAlone(): void
    {
        $session = 'session' . random_int(100000, 999999);
        $caller = $this->channelFor($session, RecordingType::Caller, SourceRole::Customer);
        $this->complete($this->jobIdOf($caller));
        $this->complete($this->jobIdOf($this->channelFor($session, RecordingType::Callee, SourceRole::Agent)));

        $replacement = $this->channelFor($session, RecordingType::Callee, SourceRole::Agent);
        $this->complete($this->jobIdOf($replacement));

        $ids = $this->conversations->channelJobIdsForCallSession($this->storeSourceId, $session);

        self::assertSame([$this->jobIdOf($replacement)], $ids[RecordingType::Callee->value]);
        self::assertSame([$this->jobIdOf($caller)], $ids[RecordingType::Caller->value]);
    }

    /**
     * A replacement becomes the current recording by **finishing**, not by being queued.
     *
     * The same rule the store page's own version fold applies. While a replacement is still
     * transcribing the recording it replaces is the one with words in it, so that is still the call's
     * Customer side — otherwise asking for a transcript would take the existing one away for as long as
     * the worker took.
     */
    public function testAReplacementStillTranscribingIsNotYetTheCurrentRecording(): void
    {
        $session = 'session' . random_int(100000, 999999);
        $original = $this->channelFor($session, RecordingType::Caller, SourceRole::Customer);
        $this->complete($this->jobIdOf($original));

        // Queued, not finished.
        $this->channelFor($session, RecordingType::Caller, SourceRole::Customer);

        self::assertSame(
            [$this->jobIdOf($original)],
            $this->conversations->channelJobIdsForCallSession($this->storeSourceId, $session)[RecordingType::Caller->value],
            'The finished recording holds the side until the replacement finishes.',
        );
    }

    /** Replaced twice is still one side. The newest finished one wins, not the first or all of them. */
    public function testReplacingTwiceStillLeavesOneCurrentRecording(): void
    {
        $session = 'session' . random_int(100000, 999999);
        $this->complete($this->jobIdOf($this->channelFor($session, RecordingType::Caller, SourceRole::Customer)));
        $this->complete($this->jobIdOf($this->channelFor($session, RecordingType::Caller, SourceRole::Customer)));
        $third = $this->channelFor($session, RecordingType::Caller, SourceRole::Customer);
        $this->complete($this->jobIdOf($third));

        self::assertSame(
            [$this->jobIdOf($third)],
            $this->conversations->channelJobIdsForCallSession($this->storeSourceId, $session)[RecordingType::Caller->value],
        );
    }

    /** With nothing finished, the newest stands in rather than the side looking absent. */
    public function testWithNothingFinishedTheNewestRecordingStandsIn(): void
    {
        $session = 'session' . random_int(100000, 999999);
        $this->channelFor($session, RecordingType::Caller, SourceRole::Customer);
        $newest = $this->channelFor($session, RecordingType::Caller, SourceRole::Customer);

        self::assertSame(
            [$this->jobIdOf($newest)],
            $this->conversations->channelJobIdsForCallSession($this->storeSourceId, $session)[RecordingType::Caller->value],
        );
    }

    /** Every version is still stored; only the answer to "which is the side" is one. */
    public function testTheReplacedRecordingIsStillThere(): void
    {
        $session = 'session' . random_int(100000, 999999);
        $original = $this->channelFor($session, RecordingType::Caller, SourceRole::Customer);
        $this->complete($this->jobIdOf($original));
        $this->complete($this->jobIdOf($this->channelFor($session, RecordingType::Caller, SourceRole::Customer)));

        self::assertSame(
            2,
            (int) $this->connection->createCommand(
                'SELECT COUNT(*) FROM {{%audio_conversations}} WHERE call_session_id = :s AND recording_type = :t',
                ['s' => $session, 't' => RecordingType::Caller->value],
            )->queryScalar(),
            'Replacement keeps the recording it replaced — that is what the version history is made of.',
        );
    }

    /** One channel of a call, as the importer writes it. */
    private function channelFor(string $session, RecordingType $type, SourceRole $role): string
    {
        return $this->queue()->enqueueConversation(
            ConversationMode::Common,
            $this->storeSourceId,
            [$role->value => $this->wavUpload('channel.wav')],
            $this->adminId,
            TranscriptionProvider::Whisper,
            false,
            $type,
            null,
            $session,
            null,
            true,
            $role,
        );
    }

    /** Stand in for the worker finishing one recording. */
    private function complete(int $jobId): void
    {
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['status' => JobStatus::COMPLETED->value],
            ['id' => $jobId],
        )->execute();
    }

    private function mixedFor(string $session): string
    {
        return $this->queue()->enqueueConversation(
            ConversationMode::Common,
            $this->storeSourceId,
            [SourceRole::Common->value => $this->wavUpload('mixed.wav')],
            $this->adminId,
            TranscriptionProvider::Whisper,
            false,
            RecordingType::Mixed,
            null,
            $session,
        );
    }

    private function jobIdOf(string $conversationPublicId): int
    {
        return (int) $this->connection->createCommand(
            'SELECT j.id FROM {{%audio_transcription_jobs}} j
               INNER JOIN {{%audio_conversations}} c ON c.id = j.conversation_id
             WHERE c.public_id = :p',
            ['p' => $conversationPublicId],
        )->queryScalar();
    }

    /**
     * Stand in for an administrator pressing Confirm.
     *
     * Written straight to the column rather than through `ReviewConversationService`, because what is
     * under test is the query that reads it: routing through the service would also need a completed
     * transcription, segments and a reviewed layer, none of which this question depends on.
     */
    private function confirmRoles(int $jobId): void
    {
        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            ['roles_confirmed_at' => '2026-09-23 11:20:50'],
            ['id' => $jobId],
        )->execute();
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

    /**
     * The card and the order id survive the enqueue, which is the only place they are written.
     *
     * Asserted by reading the conversation back rather than by trusting the insert: the write happens
     * inside the same transaction as the children, and a value dropped there would be lost silently —
     * the upload would still succeed and the history would simply be wrong.
     */
    public function testTheRecordingTypeAndOrderIdAreStoredWithTheConversation(): void
    {
        $publicId = $this->queue()->enqueueConversation(
            ConversationMode::Common,
            $this->storeSourceId,
            [SourceRole::Common->value => $this->wavUpload('caller.wav')],
            $this->adminId,
            TranscriptionProvider::Whisper,
            false,
            RecordingType::Caller,
            '16513791',
        );

        $conversation = $this->conversations->findByPublicId($publicId);

        self::assertNotNull($conversation);
        self::assertSame(RecordingType::Caller, $conversation->recordingType);
        // Stored CALLER, displayed "Customer" — the client's vocabulary on screen, the call's in the
        // column. The stored value is asserted above and on the row itself below.
        self::assertSame('Customer', $conversation->typeLabel());
        self::assertSame('16513791', $conversation->orderId);
        // None of it reaches the job: the worker's view of this recording is unchanged.
        self::assertSame(SourceRole::Common, $conversation->children[0]->sourceRole);
        self::assertSame(JobStatus::QUEUED, $conversation->children[0]->status);
    }

    /**
     * 15. The call session survives the enqueue, and a manual upload records none.
     *
     * The only value that says three recordings are one call, written in the same transaction as the
     * children. Read back rather than trusted: a value dropped there would lose the relationship
     * silently — the upload would still succeed and the Agent view would simply never derive.
     */
    public function testTheCallSessionIsStoredWithTheConversation(): void
    {
        $session = '22449119';

        $linked = $this->queue()->enqueueConversation(
            ConversationMode::Common,
            $this->storeSourceId,
            [SourceRole::Common->value => $this->wavUpload('22449119-callee.wav')],
            $this->adminId,
            TranscriptionProvider::Whisper,
            false,
            RecordingType::Callee,
            '123123',
            $session,
        );

        // The manual upload form passes no session, because it has none to pass.
        $unlinked = $this->queue()->enqueueConversation(
            ConversationMode::Common,
            $this->storeSourceId,
            [SourceRole::Common->value => $this->wavUpload('by-hand.wav')],
            $this->adminId,
        );

        self::assertSame($session, $this->conversations->findByPublicId($linked)?->callSessionId);
        self::assertNull(
            $this->conversations->findByPublicId($unlinked)?->callSessionId,
            'Not known to belong to a call, which is what every hand-made upload is.',
        );
    }

    /**
     * 11 / 5. Exactly one confirmed mixed recording, or no answer at all.
     *
     * The question the derived views turn on, asked of real MySQL because the failure it guards is a
     * query returning the first of several as though it were the only one. Two confirmed mixed
     * recordings of one call is a normal state — the same file uploaded twice produces it — and the
     * honest answer to "which one speaks for this call" is then that none of them does.
     */
    public function testOnlyAnUnambiguousConfirmedMixedRecordingAnswersForACall(): void
    {
        $session = 'session' . random_int(100000, 999999);

        // Unconfirmed: the speakers were never established, so it has nothing to lend.
        $first = $this->mixedFor($session);
        self::assertNull(
            $this->conversations->confirmedMixedJobIdForCallSession($this->storeSourceId, $session),
        );

        $firstJobId = $this->jobIdOf($first);
        $this->confirmRoles($firstJobId);

        self::assertSame(
            $firstJobId,
            $this->conversations->confirmedMixedJobIdForCallSession($this->storeSourceId, $session),
        );

        // A second confirmed mixed recording of the same call: now nothing is unambiguous.
        $this->confirmRoles($this->jobIdOf($this->mixedFor($session)));

        self::assertNull(
            $this->conversations->confirmedMixedJobIdForCallSession($this->storeSourceId, $session),
            'Two candidates is not a reason to pick one.',
        );

        // Another store's call with the same session id is never the answer for this one.
        self::assertNull(
            $this->conversations->confirmedMixedJobIdForCallSession($this->storeSourceId + 1, $session),
        );
    }

    /**
     * The backfill writes once and only into an empty column, so a second run does nothing.
     *
     * The `IS NULL` guard is in the UPDATE, not only in the read that found the row: the importer may
     * link the same conversation between the two, and the provider's own value outranks one recovered
     * from a filename.
     */
    public function testLinkingACallIsIdempotentAndNeverOverwrites(): void
    {
        $publicId = $this->queue()->enqueueConversation(
            ConversationMode::Common,
            $this->storeSourceId,
            [SourceRole::Common->value => $this->wavUpload('22449119.wav')],
            $this->adminId,
        );

        $id = (int) $this->connection
            ->createCommand(
                'SELECT id FROM {{%audio_conversations}} WHERE public_id = :p',
                ['p' => $publicId],
            )
            ->queryScalar();

        self::assertTrue($this->conversations->recordCallSession($id, '22449119'));
        self::assertFalse(
            $this->conversations->recordCallSession($id, '99999999'),
            'Already linked: the second attempt changes nothing and says so.',
        );
        self::assertSame('22449119', $this->conversations->callSessionFor($id));
    }

    /**
     * **The claim this whole feature rests on: downloading does not transcribe.**
     *
     * A recording acquired without a transcript being asked for exists, is playable, and is invisible to
     * every part of the transcription machinery — not by a flag somebody has to remember to check, but
     * because `NOT_REQUESTED` is in neither `activeValues()` nor `terminalValues()`, which is what the
     * claim query, the queue limit and the retention purge are all built from.
     */
    public function testADownloadOnlyRecordingIsNeverClaimedAndCostsNoQueueSlot(): void
    {
        $before = $this->jobs->countActive();

        $publicId = $this->queue()->enqueueConversation(
            ConversationMode::Common,
            $this->storeSourceId,
            [SourceRole::Common->value => $this->wavUpload('22613839.wav')],
            $this->adminId,
            TranscriptionProvider::Whisper,
            false,
            RecordingType::Mixed,
            '16655261',
            '22613839',
            '2026-10-01 01:43:39',
            // The whole point.
            transcribe: false,
        );

        $conversation = $this->conversations->findByPublicId($publicId);
        self::assertNotNull($conversation);

        $job = $this->jobs->findByPublicId($conversation->children[0]->publicId);
        self::assertNotNull($job);

        self::assertSame(JobStatus::NOT_REQUESTED, $job->status, 'Not a state any worker looks for.');
        self::assertSame('Ready for transcription', $job->status->label());

        // Playable at once: the bytes are in permanent storage, not in a workspace the worker owns.
        self::assertNotNull($job->retainedAudioPath, 'Retained, so the player can serve it today.');
        self::assertNull($job->storedAudioPath, 'And no workspace copy to send a worker looking for.');

        // Invisible to the queue, by construction rather than by a WHERE clause somebody added.
        self::assertSame($before, $this->jobs->countActive(), 'It occupies no queue slot.');
        self::assertNull($this->jobs->queuePositionOf($job->id), 'It is not waiting in line.');
        self::assertNotContains($job->publicId, $this->jobs->activePublicIds());

        // And the call time came across verbatim — not parsed, not given a timezone it never carried.
        self::assertSame('2026-10-01 01:43:39', $conversation->callTimeRaw);
        self::assertSame('22613839', $conversation->callSessionId);
    }

    /**
     * Asking turns it into ordinary queued work, once, however many times the button is pressed.
     *
     * The provider is captured at this moment and not at download — a recording acquired while the
     * server said Whisper is transcribed by whatever it says when somebody actually wants the text.
     */
    public function testAskingForATranscriptQueuesItExactlyOnce(): void
    {
        $publicId = $this->queue()->enqueueConversation(
            ConversationMode::Common,
            $this->storeSourceId,
            [SourceRole::Common->value => $this->wavUpload('22613709.wav')],
            $this->adminId,
            TranscriptionProvider::Whisper,
            false,
            RecordingType::Mixed,
            null,
            '22613709',
            null,
            transcribe: false,
        );

        $conversation = $this->conversations->findByPublicId($publicId);
        self::assertNotNull($conversation);
        $id = (int) $this->connection->createCommand(
            'SELECT id FROM {{%audio_transcription_jobs}} WHERE public_id = :p',
            ['p' => $conversation->children[0]->publicId],
        )->queryScalar();

        self::assertTrue(
            $this->jobs->requestTranscription($id, TranscriptionProvider::Deepgram),
            'The first press is the one that asks.',
        );
        self::assertFalse(
            $this->jobs->requestTranscription($id, TranscriptionProvider::Whisper),
            'A second press changes nothing — and cannot change the engine out from under the first.',
        );

        $job = $this->jobs->findById($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::QUEUED, $job->status);
        self::assertSame('Transcription requested', $job->status->label());
        self::assertSame(
            TranscriptionProvider::Deepgram,
            $job->transcriptionProvider(),
            'Chosen when it was asked for, not when it was downloaded.',
        );

        // Now it is ordinary work, and the retained copy is still there for the worker to restore from.
        self::assertNotNull($job->retainedAudioPath);
    }

    /** An upload that named neither: both stay null, and the conversation reads as it always did. */
    public function testAnUploadThatNamesNeitherStoresNull(): void
    {
        $publicId = $this->queue()->enqueueConversation(
            ConversationMode::Common,
            $this->storeSourceId,
            [SourceRole::Common->value => $this->wavUpload('mixed.wav')],
            $this->adminId,
        );

        $conversation = $this->conversations->findByPublicId($publicId);

        self::assertNotNull($conversation);
        self::assertNull($conversation->recordingType);
        self::assertNull($conversation->orderId);
        // Its mode's label, which says the same words a MIXED recording does — an upload from before
        // the column and one made today are the same thing and must not read differently.
        self::assertSame('Mix / Common', $conversation->typeLabel());
    }

    /** A pair can carry an order id too — one upload, one order, however many recordings. */
    public function testASeparatePairCarriesOneOrderIdForBothRecordings(): void
    {
        $publicId = $this->queue()->enqueueConversation(
            ConversationMode::Separate,
            $this->storeSourceId,
            [
                SourceRole::Customer->value => $this->wavUpload('customer.wav'),
                SourceRole::Agent->value => $this->wavUpload('agent.wav'),
            ],
            $this->adminId,
            TranscriptionProvider::Whisper,
            false,
            null,
            '16513791',
        );

        $conversation = $this->conversations->findByPublicId($publicId);

        self::assertNotNull($conversation);
        self::assertSame('16513791', $conversation->orderId);
        self::assertNull($conversation->recordingType, 'A pair is described by its mode.');
        self::assertSame('Separate Customer + Agent', $conversation->typeLabel());
        self::assertCount(2, $conversation->children);
    }

    /**
     * Every channel of an imported call, through the seam the importer actually calls.
     *
     * This is the end-to-end statement of the business rule, at the one boundary both the Order58
     * importer and the manual upload form pass through. It is here rather than in a unit test because
     * the thing that was wrong before could not be seen from the policy alone: the policy answered
     * correctly and the row still came out transcribed.
     *
     * The mixed recording is audio in every shape of call. There is no sibling lookup, no ordering
     * dependency and nothing to resolve first — which is the point: the same file gets the same answer
     * whether it arrives alone, first or last.
     *
     * @dataProvider importedChannels
     */
    public function testEachImportedChannelIsProcessedByWhatItIs(
        string $recordingType,
        string $expectedRole,
        string $expectedStatus,
    ): void {
        $path = $this->wavFile('channel-' . strtolower($recordingType) . '.wav');

        $outcome = $this->ingestion()->ingestFile(
            $this->storeSourceId,
            $path,
            $recordingType . '.wav',
            $recordingType,
            '16513791',
            'WHISPER',
            false,
            $this->adminId,
            '22633299',
            '2026-10-01 01:43:12',
            // What a transcribing batch asks for. The policy withdraws it for a mixed recording and
            // honours it for a side, which is the whole assertion below.
            true,
        );

        self::assertTrue($outcome->wasQueued(), $outcome->firstProblem());

        @unlink($path);

        $conversation = $this->conversations->findByPublicId((string) $outcome->conversationPublicId);
        $child = $conversation?->singleChild();

        self::assertNotNull($child);
        self::assertSame($recordingType, $conversation?->recordingType?->value);
        self::assertSame($expectedRole, $child->sourceRole->value);
        self::assertSame($expectedStatus, $child->status->value);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function importedChannels(): iterable
    {
        yield 'mixed is audio only' => ['MIXED', 'COMMON', 'NOT_REQUESTED'];
        yield 'caller is the customer' => ['CALLER', 'CUSTOMER', 'QUEUED'];
        yield 'callee is the agent' => ['CALLEE', 'AGENT', 'QUEUED'];
    }

    /**
     * A deterministic channel is **one** child carrying the side the importer declared.
     *
     * The regression this exists for: an earlier draft read the processing policy's answer as a
     * conversation mode, which made a caller recording a SEPARATE upload — so the enqueue went looking
     * for a second, Agent file that an imported channel never has, and every caller and callee import
     * failed before writing anything. One file is one child; which side it holds is the child's own
     * fact, and it is the fact that suppresses diarization and labels every screen.
     *
     * Asserted against real MySQL because the shape is a database guarantee: the row has to come back
     * with the declared role on it, not merely be asked for with one.
     */
    public function testADeclaredChannelIsOneChildCarryingItsSide(): void
    {
        foreach (
            [
                [RecordingType::Caller, SourceRole::Customer],
                [RecordingType::Callee, SourceRole::Agent],
            ] as [$type, $role]
        ) {
            $publicId = $this->queue()->enqueueConversation(
                ConversationMode::Common,
                $this->storeSourceId,
                [$role->value => $this->wavUpload('channel.wav')],
                $this->adminId,
                TranscriptionProvider::Whisper,
                false,
                $type,
                '22633299',
                '22633299',
                null,
                true,
                $role,
            );

            $conversation = $this->conversations->findByPublicId($publicId);

            self::assertNotNull($conversation);
            self::assertSame(ConversationMode::Common, $conversation->mode);
            self::assertSame($type, $conversation->recordingType);
            self::assertTrue($conversation->hasValidShape(), $type->value . ' is a valid one-child shape');
            self::assertCount(1, $conversation->children);
            self::assertNotNull($conversation->childFor($role));
            self::assertNull(
                $conversation->childFor(SourceRole::Common),
                'a declared channel must not be written as a mixed recording',
            );
        }
    }

    /**
     * A mixed recording of a complete group keeps its audio and asks for no transcript.
     *
     * NOT_REQUESTED rather than a missing row: the file is the playable original of the call, and the
     * "Transcribe audio" button on the store page is what makes the decision recoverable if a side
     * later turns out to be unusable.
     */
    public function testAMixedRecordingCanBeStoredWithoutAskingForATranscript(): void
    {
        $publicId = $this->queue()->enqueueConversation(
            ConversationMode::Common,
            $this->storeSourceId,
            [SourceRole::Common->value => $this->wavUpload('mixed.wav')],
            $this->adminId,
            TranscriptionProvider::Whisper,
            false,
            RecordingType::Mixed,
            '22633299',
            '22633299',
            null,
            false,
        );

        $conversation = $this->conversations->findByPublicId($publicId);
        $child = $conversation?->singleChild();

        self::assertNotNull($child);
        self::assertSame(JobStatus::NOT_REQUESTED, $child->status);
        self::assertTrue($conversation?->hasValidShape());
    }

    /**
     * A declared side may not be smuggled into a SEPARATE upload.
     *
     * That mode's two children are what it means, and accepting one would store half a pair as though
     * it were whole — which would then read as a complete Customer + Agent upload on every screen.
     */
    public function testADeclaredSideIsRefusedForASeparateUpload(): void
    {
        $this->expectException(AudioTranscriptionException::class);

        $this->queue()->enqueueConversation(
            ConversationMode::Separate,
            $this->storeSourceId,
            [SourceRole::Customer->value => $this->wavUpload('customer.wav')],
            $this->adminId,
            TranscriptionProvider::Whisper,
            false,
            null,
            null,
            null,
            null,
            true,
            SourceRole::Customer,
        );
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

    private function ingestion(): AudioIngestionService
    {
        return new AudioIngestionService(new AudioUploadValidator($this->settings()), $this->queue());
    }

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
    /**
     * The same WAV on disk, for the seam the Order58 importer actually uses.
     *
     * {@see AudioIngestionService::ingestFile()} takes a path rather than an upload, because the
     * importer has downloaded a file rather than received one. Removed by the caller.
     */
    private function wavFile(string $filename): string
    {
        // The system temp dir, not this suite's workspace: the workspace is created lazily by the
        // storage on its first write, and the importer's file exists before any of that happens.
        $path = sys_get_temp_dir() . '/kf-' . bin2hex(random_bytes(6)) . '-' . $filename;
        $samples = 2000;
        $data = str_repeat("\0\0", $samples);
        $bytes = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('V', 16)
            . pack('v', 1) . pack('v', 1) . pack('V', 8000) . pack('V', 16000)
            . pack('v', 2) . pack('v', 16) . 'data' . pack('V', strlen($data)) . $data;

        file_put_contents($path, $bytes);

        return $path;
    }

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
