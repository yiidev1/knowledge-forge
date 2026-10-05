<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_column;
use function array_map;
use function bin2hex;
use function gmdate;
use function is_array;
use function json_decode;
use function json_encode;
use function random_bytes;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * What the Details dialog is served for a deterministic call's mixed recording.
 *
 * ## Why this is a served test rather than a unit one
 *
 * Everything between the route and the browser has to line up for a correction to land on the right
 * message: the mixed row has to be allowed through a gate written for completed recordings, the
 * projection has to find both channels through two scoped lookups, and each rendered message has to
 * carry a url, an index and a version that all describe the **same** child. A unit test can prove the
 * projection's arithmetic — and one does, exhaustively — but it cannot prove the wiring, and the wiring
 * is where a sentence gets written over the wrong sentence.
 *
 * ## What the fixture is
 *
 * One imported call, as the policy now stores it: a Customer channel and an Agent channel, each
 * transcribed alone with its own single-speaker segments, and a mixed recording in NOT_REQUESTED that
 * kept only its audio. Written directly, because no worker runs in this suite and the point here is the
 * screens.
 *
 * Nothing in this suite touches a row it did not create, and every row it creates hangs off its own
 * administrator so the teardown can find all of them.
 */
final class AudioToTextCombinedDetailsCest
{
    private const ADMIN = '__kf_a2t_combined_admin__';
    private const PASSWORD = 'CombinedPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    private const STORE = 987654341;
    private const STORE_NAME = '__KF Combined Store__';
    private const SESSION = '22633299';
    private const ORDER = '16513791';

    /** The two sides carry different counts on purpose: a shared one would hide a mixed-up lock. */
    private const CUSTOMER_VERSION = 3;
    private const AGENT_VERSION = 5;

    private ConnectionInterface $connection;
    private int $adminId = 0;
    private string $mixedJobPublicId = '';
    private string $customerJobPublicId = '';
    private string $agentJobPublicId = '';

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        $this->adminId = (int) $this->connection
            ->createCommand('SELECT id FROM {{%admin_users}} WHERE username = :u', [':u' => self::ADMIN])
            ->queryScalar();

        $this->createStore();
        $this->seedCall();
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    /**
     * The mixed recording's dialog opens, and opens as a combined conversation.
     *
     * It is NOT_REQUESTED and holds no transcript, so the gate this endpoint has always applied would
     * answer 404 for it. That it does not is the whole feature.
     */
    public function theMixedRecordingIsServedAsACombinedConversation(WebTester $I): void
    {
        $payload = $this->fragment($I, $this->mixedJobPublicId);

        Assert::assertIsArray($payload['combined'] ?? null, 'the dialog must be told it is combined');
        Assert::assertSame('COMPLETE', $payload['combined']['state']);
        Assert::assertTrue($payload['combined']['interleaved']);
        Assert::assertNull($payload['combined']['explanation'], 'a complete call has nothing to warn about');
    }

    /** Both sides, interleaved by the clock rather than grouped by recording. */
    public function bothSidesAppearInCallOrder(WebTester $I): void
    {
        $payload = $this->fragment($I, $this->mixedJobPublicId);

        Assert::assertSame(
            [
                ['Agent', 'Good evening, Wah Sing.'],
                ['Customer', 'One wonton soup please.'],
                ['Agent', 'Anything else?'],
                ['Customer', 'That will be it.'],
            ],
            array_map(
                static fn(array $turn): array => [$turn['label'], $turn['text']],
                $payload['turns'],
            ),
        );
    }

    /**
     * Every message is addressed to the recording that owns it — url, index and version together.
     *
     * The combined positions here are 0,1,2,3 and the owner-local indices are 0,0,1,1. Position 2 is the
     * Agent's **second** message, and reading its index off the screen would aim a correction at the
     * Customer's third — which in this call does not exist, and in a longer one holds somebody else's
     * words.
     */
    public function everyMessageCarriesItsOwnersAddress(WebTester $I): void
    {
        $payload = $this->fragment($I, $this->mixedJobPublicId);

        $expected = [
            [$this->agentJobPublicId, 0, self::AGENT_VERSION],
            [$this->customerJobPublicId, 0, self::CUSTOMER_VERSION],
            [$this->agentJobPublicId, 1, self::AGENT_VERSION],
            [$this->customerJobPublicId, 1, self::CUSTOMER_VERSION],
        ];

        foreach ($payload['turns'] as $position => $turn) {
            [$owner, $index, $version] = $expected[$position];

            Assert::assertSame($owner, $turn['owner'], 'message ' . $position . ' names its owner');
            Assert::assertSame($index, $turn['index'], 'message ' . $position . ' keeps its local index');
            Assert::assertSame($version, $turn['version'], 'message ' . $position . ' locks its own row');

            // And the urls agree with all three, because the url is what actually performs the write.
            Assert::assertSame(
                '/audio-to-text/job/' . $owner . '/review/turn/' . $index . '/text',
                $turn['urls']['text'],
            );
            Assert::assertSame(
                '/audio-to-text/job/' . $owner . '/review/turn/' . $index . '/merge',
                $turn['urls']['merge'],
            );
        }
    }

    /** The mixed recording's own identity appears on no message and in no url. */
    public function theMixedRecordingOwnsNothing(WebTester $I): void
    {
        $payload = $this->fragment($I, $this->mixedJobPublicId);

        foreach ($payload['turns'] as $turn) {
            Assert::assertNotSame($this->mixedJobPublicId, $turn['owner']);
            Assert::assertStringNotContainsString($this->mixedJobPublicId, $turn['urls']['text']);
            Assert::assertStringNotContainsString($this->mixedJobPublicId, (string) $turn['urls']['merge']);
        }

        // The conversation-level version is deliberately unusable: nothing here performs a
        // conversation-level operation, and -1 matches no row if anything tried.
        Assert::assertSame(-1, $payload['version']);
    }

    /**
     * None of the three things a guessed attribution needs is offered.
     *
     * No neutral speaker label, because nothing was guessed. No confirmation, because there is nothing
     * left to assert. No move, because a message cannot change speaker when the speaker is which file it
     * arrived in.
     */
    public function nothingAboutGuessedSpeakersIsOffered(WebTester $I): void
    {
        $payload = $this->fragment($I, $this->mixedJobPublicId);

        Assert::assertFalse($payload['canConfirm']);
        Assert::assertTrue($payload['rolesPublished']);

        foreach ($payload['turns'] as $turn) {
            Assert::assertTrue($turn['confirmed']);
            Assert::assertFalse($turn['canMove']);
            Assert::assertNull($turn['urls']['moveText']);
            Assert::assertStringNotContainsString('Speaker', $turn['label']);
            Assert::assertContains($turn['label'], ['Customer', 'Agent']);
        }
    }

    /** The wording of every message stays correctable, which is the one edit that still means something. */
    public function theWordingOfEveryMessageIsStillCorrectable(WebTester $I): void
    {
        $payload = $this->fragment($I, $this->mixedJobPublicId);

        foreach ($payload['turns'] as $turn) {
            Assert::assertTrue($turn['canEdit']);
        }
    }

    /** The mixed recording's own audio is still what the dialog plays. */
    public function theMixedAudioIsStillPlayable(WebTester $I): void
    {
        $payload = $this->fragment($I, $this->mixedJobPublicId);

        Assert::assertTrue($payload['audio']['original']['available']);
        Assert::assertSame(
            '/audio-to-text/job/' . $this->mixedJobPublicId . '/original/file',
            $payload['audio']['original']['url'],
        );
    }

    /**
     * Both sides are named, each linking to its own correction page.
     *
     * That is where discarding a side's corrections and reading its revisions live. There is no mixed
     * level for either: they are per recording, and there are two.
     */
    public function eachSideLinksToItsOwnCorrectionPage(WebTester $I): void
    {
        $payload = $this->fragment($I, $this->mixedJobPublicId);

        Assert::assertSame(
            ['CUSTOMER', 'AGENT'],
            array_column($payload['combined']['children'], 'role'),
        );
        Assert::assertSame(
            [
                '/audio-to-text/job/' . $this->customerJobPublicId . '/review',
                '/audio-to-text/job/' . $this->agentJobPublicId . '/review',
            ],
            array_column($payload['combined']['children'], 'url'),
        );
        Assert::assertFalse(
            isset($payload['urls']['revert']),
            'there is no mixed-level discard to offer, so none is sent',
        );
        Assert::assertFalse(isset($payload['urls']['confirm']));
    }

    /**
     * A child's own dialog shows its own words, under its own declared name.
     *
     * The other half of "one copy": the Customer recording is authoritative for the Customer's messages,
     * and opening it directly must show exactly what the combined view showed — not a neutral speaker
     * label, and not a confirmation prompt for a side nobody guessed.
     */
    public function aChildDialogShowsItsOwnSideUnderItsDeclaredName(WebTester $I): void
    {
        $payload = $this->fragment($I, $this->customerJobPublicId);

        Assert::assertSame('Customer', $payload['voice']);
        Assert::assertFalse($payload['canConfirm']);
        Assert::assertSame(self::CUSTOMER_VERSION, $payload['version']);
        Assert::assertSame(
            ['One wonton soup please.', 'That will be it.'],
            array_map(static fn(array $turn): string => $turn['text'], $payload['turns']),
        );

        foreach ($payload['turns'] as $turn) {
            Assert::assertSame('Customer', $turn['label']);
            Assert::assertFalse($turn['canMove'], 'one person has nobody to move a message to');
            Assert::assertTrue($turn['canEdit']);
        }
    }

    /** A correction made from the combined view appears on the child, and the other way round. */
    public function aCorrectionFromEitherViewIsVisibleInTheOther(WebTester $I): void
    {
        $this->signIn($I);

        // Posted exactly as the dialog posts it: the owner's url, the owner's index, the owner's version.
        $I->sendAjaxPostRequest(
            '/audio-to-text/job/' . $this->customerJobPublicId . '/review/turn/0/text',
            [
                'text' => 'One wonton soup please, extra chilli.',
                'expected_review_count' => (string) self::CUSTOMER_VERSION,
                '_csrf' => $this->csrfToken($I),
            ],
        );

        $combined = $this->fragment($I, $this->mixedJobPublicId, signIn: false);
        $child = $this->fragment($I, $this->customerJobPublicId, signIn: false);

        Assert::assertSame('One wonton soup please, extra chilli.', $combined['turns'][1]['text']);
        Assert::assertSame('One wonton soup please, extra chilli.', $child['turns'][0]['text']);
        // And the Agent's side is untouched, with its own version where it was.
        Assert::assertSame('Good evening, Wah Sing.', $combined['turns'][0]['text']);
        Assert::assertSame(self::AGENT_VERSION, $combined['turns'][0]['version']);
    }

    /**
     * A stale version is refused, and the message it aimed at is unchanged.
     *
     * The projection renders each message with its owner's count, so a dialog left open while somebody
     * else corrects that side carries a number that has moved on — and the existing guard is what stops
     * it overwriting their work.
     */
    public function aStaleVersionFromTheCombinedViewIsRefused(WebTester $I): void
    {
        $this->signIn($I);

        $I->sendAjaxPostRequest(
            '/audio-to-text/job/' . $this->customerJobPublicId . '/review/turn/0/text',
            [
                'text' => 'Written from a stale dialog.',
                'expected_review_count' => (string) (self::CUSTOMER_VERSION - 1),
                '_csrf' => $this->csrfToken($I),
            ],
        );

        $combined = $this->fragment($I, $this->mixedJobPublicId, signIn: false);

        Assert::assertSame('One wonton soup please.', $combined['turns'][1]['text']);
        Assert::assertSame(self::CUSTOMER_VERSION, $combined['turns'][1]['version']);
    }

    /**
     * A mixed recording whose call has no sides says so, and still plays.
     *
     * The commonest state there is now: nothing transcribes a mixed recording, so a call whose Customer
     * and Agent sides were never recorded has no transcript at all. That is the accepted outcome, and
     * the dialog has to state it — a 404 would leave an administrator with a recording they can see in
     * the table and cannot open.
     */
    public function aMixedRecordingWithNoSidesExplainsItselfAndStillPlays(WebTester $I): void
    {
        $this->deleteChannels();

        $payload = $this->fragment($I, $this->mixedJobPublicId);

        Assert::assertSame('NO_CHILDREN', $payload['combined']['state']);
        Assert::assertStringContainsString(
            'No Customer or Agent transcript is available for this call',
            (string) $payload['combined']['explanation'],
        );
        Assert::assertSame([], $payload['turns']);
        Assert::assertTrue($payload['audio']['original']['available'], 'the recording still plays');
    }

    /** One side only: its half is shown, and the dialog says which half is missing. */
    public function aCallWithOnlyTheCustomerSideShowsItAndSaysSo(WebTester $I): void
    {
        $this->deleteChannel($this->agentJobPublicId);

        $payload = $this->fragment($I, $this->mixedJobPublicId);

        Assert::assertSame('CUSTOMER_ONLY', $payload['combined']['state']);
        Assert::assertNotNull($payload['combined']['explanation']);
        Assert::assertSame(
            ['One wonton soup please.', 'That will be it.'],
            array_map(static fn(array $turn): string => $turn['text'], $payload['turns']),
        );
    }

    /**
     * Nothing offers to transcribe the mixed recording, and the endpoint refuses if asked anyway.
     *
     * Withholding a control is not a rule — this endpoint takes a POST. The refusal names the recording
     * rather than its status, because the status is not what is wrong with the request.
     */
    public function theMixedRecordingCannotBeTranscribedEvenByADirectRequest(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage('/audio-to-text/job/' . $this->customerJobPublicId . '/review');
        $token = $I->grabValueFrom('input[name=_csrf]');

        $I->sendAjaxPostRequest(
            '/audio-to-text/job/' . $this->mixedJobPublicId . '/transcribe',
            ['_csrf' => $token],
        );

        $I->seeResponseCodeIs(409);
        $I->see('kept as audio only');

        Assert::assertSame('NOT_REQUESTED', $this->statusOf($this->mixedJobPublicId));
    }

    /**
     * A mixed recording transcribed before this rule existed keeps rendering its own conversation.
     *
     * The projection is consulted only when the mixed row holds nothing of its own, so a historical
     * call that was diarized and corrected is never replaced by a combined view of recordings that
     * happen to sit beside it. Nothing is deleted, reset or re-decided for it.
     */
    public function aHistoricallyTranscribedMixedRecordingKeepsItsOwnConversation(WebTester $I): void
    {
        $this->makeMixedHistoricallyTranscribed();

        $payload = $this->fragment($I, $this->mixedJobPublicId);

        Assert::assertFalse(
            isset($payload['combined']),
            'a recording with its own conversation is never served as a projection',
        );
        Assert::assertSame(
            ['Hello, can I take your order?', 'Yes, one wonton soup.'],
            array_map(static fn(array $turn): string => $turn['text'], $payload['turns']),
        );
        // Its own version, its own turns, its own controls — exactly as before this feature existed.
        Assert::assertSame(0, $payload['version']);
        Assert::assertTrue($payload['turns'][0]['canMove']);
    }

    /** Both sides of one call, uploaded by hand under one order, combine exactly as imported ones do. */
    public function aManuallyUploadedSetGroupsByItsOrder(WebTester $I): void
    {
        $this->forgetCallSessions();

        $payload = $this->fragment($I, $this->mixedJobPublicId);

        Assert::assertSame('COMPLETE', $payload['combined']['state']);
        Assert::assertSame(
            [
                ['Agent', 'Good evening, Wah Sing.'],
                ['Customer', 'One wonton soup please.'],
                ['Agent', 'Anything else?'],
                ['Customer', 'That will be it.'],
            ],
            array_map(
                static fn(array $turn): array => [$turn['label'], $turn['text']],
                $payload['turns'],
            ),
        );
    }

    private function statusOf(string $jobPublicId): string
    {
        return (string) $this->connection
            ->createCommand(
                'SELECT status FROM {{%audio_transcription_jobs}} WHERE public_id = :p',
                [':p' => $jobPublicId],
            )
            ->queryScalar();
    }

    /** Drops the call session from every conversation, leaving the order as the only grouping. */
    private function forgetCallSessions(): void
    {
        $this->connection->createCommand()->update(
            '{{%audio_conversations}}',
            ['call_session_id' => null],
            ['store_source_id' => self::STORE],
        )->execute();
    }

    private function deleteChannels(): void
    {
        $this->deleteChannel($this->customerJobPublicId);
        $this->deleteChannel($this->agentJobPublicId);
    }

    private function deleteChannel(string $jobPublicId): void
    {
        $this->connection->createCommand()
            ->delete('{{%audio_transcription_jobs}}', ['public_id' => $jobPublicId])
            ->execute();
    }

    /**
     * The mixed row as it would have been left by the old pipeline: completed, diarized, confirmed.
     *
     * Written onto the existing row rather than seeded separately, so the test is unambiguously about
     * the same recording the other tests see as audio-only.
     */
    private function makeMixedHistoricallyTranscribed(): void
    {
        $segments = json_encode([
            ['start_ms' => 0, 'end_ms' => 2000, 'speaker' => 'SPEAKER_00', 'role' => 'AGENT',
                'text' => 'Hello, can I take your order?', 'confidence' => 0.9],
            ['start_ms' => 2200, 'end_ms' => 4000, 'speaker' => 'SPEAKER_01', 'role' => 'CUSTOMER',
                'text' => 'Yes, one wonton soup.', 'confidence' => 0.9],
        ], JSON_THROW_ON_ERROR);

        $this->connection->createCommand()->update(
            '{{%audio_transcription_jobs}}',
            [
                'status' => 'COMPLETED',
                'processing_stage' => 'COMPLETED',
                'transcript' => 'Hello, can I take your order? Yes, one wonton soup.',
                'agent_text' => 'Hello, can I take your order?',
                'customer_text' => 'Yes, one wonton soup.',
                'speaker_segments' => $segments,
                'speaker_separation_status' => 'COMPLETED',
                'speaker_separation_method' => 'sherpa-onnx',
                'speaker_role_confidence' => 0.9,
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['public_id' => $this->mixedJobPublicId],
        )->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function fragment(WebTester $I, string $jobPublicId, bool $signIn = true): array
    {
        if ($signIn) {
            $this->signIn($I);
        }

        $I->amOnPage('/audio-to-text/job/' . $jobPublicId . '/review/fragment');
        $I->seeResponseCodeIsSuccessful();

        $payload = json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);

        Assert::assertTrue(is_array($payload), 'the endpoint must answer JSON');

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function csrfToken(WebTester $I): string
    {
        $I->amOnPage('/audio-to-text/job/' . $this->customerJobPublicId . '/review');

        return $I->grabValueFrom('input[name=_csrf]');
    }

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    /**
     * One imported call, in the three rows the processing policy now produces.
     *
     * The channel jobs carry `source_role` and single-speaker `speaker_segments` with the channel marker
     * the segmenter writes, and **no** separation columns: nothing was inferred, so nothing is claimed.
     * The mixed job carries audio and nothing else.
     */
    private function seedCall(): void
    {
        $this->customerJobPublicId = $this->seedChannel(
            'CALLER',
            'CUSTOMER',
            self::CUSTOMER_VERSION,
            [
                ['start_ms' => 2000, 'end_ms' => 3000, 'speaker' => 'CHANNEL', 'role' => 'CUSTOMER',
                    'text' => 'One wonton soup please.', 'confidence' => 1.0],
                ['start_ms' => 8000, 'end_ms' => 9000, 'speaker' => 'CHANNEL', 'role' => 'CUSTOMER',
                    'text' => 'That will be it.', 'confidence' => 1.0],
            ],
        );

        $this->agentJobPublicId = $this->seedChannel(
            'CALLEE',
            'AGENT',
            self::AGENT_VERSION,
            [
                ['start_ms' => 0, 'end_ms' => 1000, 'speaker' => 'CHANNEL', 'role' => 'AGENT',
                    'text' => 'Good evening, Wah Sing.', 'confidence' => 1.0],
                ['start_ms' => 5000, 'end_ms' => 6000, 'speaker' => 'CHANNEL', 'role' => 'AGENT',
                    'text' => 'Anything else?', 'confidence' => 1.0],
            ],
        );

        $this->mixedJobPublicId = $this->seedMixed();
    }

    /**
     * @param list<array<string, mixed>> $segments
     */
    private function seedChannel(string $type, string $role, int $reviewCount, array $segments): string
    {
        $conversationId = $this->seedConversation($type);
        $publicId = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d H:i:s');

        // The reviewed layer is what `review_count` describes, so a non-zero count needs one — otherwise
        // the row would claim corrections it does not hold, which is not a state the pipeline produces.
        $reviewed = json_encode($segments, JSON_THROW_ON_ERROR);

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => $publicId,
            'conversation_id' => $conversationId,
            'source_role' => $role,
            'uploaded_by_admin_id' => $this->adminId,
            'status' => 'COMPLETED',
            'processing_stage' => 'COMPLETED',
            'original_filename' => self::SESSION . '-' . $type . '.wav',
            'retained_audio_path' => 'source.wav',
            'duration_seconds' => 12.5,
            'transcript' => 'one side of the call',
            'agent_text' => $role === 'AGENT' ? 'one side of the call' : null,
            'customer_text' => $role === 'CUSTOMER' ? 'one side of the call' : null,
            'speaker_segments' => json_encode($segments, JSON_THROW_ON_ERROR),
            // NULL, every one of them: the side was declared at import, so no diarization ran and there
            // is no outcome to record.
            'speaker_separation_status' => null,
            'reviewed_segments' => $reviewed,
            'review_count' => $reviewCount,
            'reviewed_by_admin_id' => $this->adminId,
            'reviewed_at' => $now,
            'created_at' => $now,
            'started_at' => $now,
            'completed_at' => $now,
        ])->execute();

        return $publicId;
    }

    private function seedMixed(): string
    {
        $conversationId = $this->seedConversation('MIXED');
        $publicId = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => $publicId,
            'conversation_id' => $conversationId,
            'source_role' => 'COMMON',
            'uploaded_by_admin_id' => $this->adminId,
            // Outside both the active and the terminal lists, so no worker will ever claim it and it
            // costs no queue slot — while the file stays playable from the moment the row exists.
            'status' => 'NOT_REQUESTED',
            'processing_stage' => 'QUEUED',
            'original_filename' => self::SESSION . '.wav',
            'retained_audio_path' => 'source.wav',
            'duration_seconds' => 12.5,
            'created_at' => $now,
        ])->execute();

        return $publicId;
    }

    private function seedConversation(string $type): int
    {
        $this->connection->createCommand()->insert('{{%audio_conversations}}', [
            'public_id' => bin2hex(random_bytes(16)),
            'store_source_id' => self::STORE,
            'mode' => 'COMMON',
            'generate_ai_audio' => 0,
            'recording_type' => $type,
            'order_id' => self::ORDER,
            'call_session_id' => self::SESSION,
            'uploaded_by_admin_id' => $this->adminId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ])->execute();

        return (int) $this->connection->getLastInsertID();
    }

    private function createStore(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->createCommand()->insert('{{%order58_stores}}', [
            'source_id' => self::STORE,
            'name' => self::STORE_NAME,
            'active' => 1,
            'sync_hash' => str_repeat('0', 64),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();

        $this->connection->createCommand()->insert('{{%knowledge_bases}}', [
            'name' => self::STORE_NAME,
            'slug' => 'kf-combined-store-' . self::STORE,
            'source_system' => 'order58',
            'source_store_id' => self::STORE,
            'source_name' => self::STORE_NAME,
            'source_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->execute();
    }

    /**
     * Scoped to this suite's own administrator, never a blanket delete.
     *
     * This database is shared with real use and conversations are kept indefinitely, so an unscoped
     * delete would destroy somebody's actual recordings. Revisions first, then jobs, then conversations:
     * both foreign keys are RESTRICT.
     */
    private function cleanup(): void
    {
        $adminIds = (new Query($this->connection))
            ->select('id')
            ->from('{{%admin_users}}')
            ->where(['username' => self::ADMIN])
            ->column();

        if ($adminIds !== []) {
            $jobIds = (new Query($this->connection))
                ->select('id')
                ->from('{{%audio_transcription_jobs}}')
                ->where(['uploaded_by_admin_id' => $adminIds])
                ->column();

            if ($jobIds !== []) {
                $this->connection->createCommand()
                    ->delete('{{%audio_segment_revisions}}', ['job_id' => $jobIds])
                    ->execute();
            }

            $this->connection->createCommand()
                ->delete('{{%audio_transcription_jobs}}', ['uploaded_by_admin_id' => $adminIds])
                ->execute();
            $this->connection->createCommand()
                ->delete('{{%audio_conversations}}', ['uploaded_by_admin_id' => $adminIds])
                ->execute();
        }

        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['source_store_id' => self::STORE]);
        IntegrationDb::cleanup($this->connection, '{{%order58_stores}}', ['source_id' => self::STORE]);
        IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => self::ADMIN]);
    }
}
