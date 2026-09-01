<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioToText;

use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Application\ReviewConversationService;
use App\AudioToText\Application\Speaker\SpeakerSegmentsDecoder;
use App\AudioToText\Domain\Exception\ReviewConflict;
use App\AudioToText\Domain\Exception\ReviewRejected;
use App\AudioToText\Domain\ReviewOperation;
use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Infrastructure\DbSegmentRevisionRepository;
use App\AudioToText\Infrastructure\DbTranscriptionJobRepository;
use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Shared\Application\Transaction\TransactionalRunner;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function bin2hex;
use function json_encode;
use function random_bytes;

/**
 * Corrections end to end, against real MySQL and a real transaction.
 *
 * Two assertions carry the design:
 *
 * - {@see testNoOperationEverTouchesTheMachineResult} reads the four machine-written columns back after
 *   every operation type and compares them byte for byte. "We were careful not to write them" is worth
 *   much less than a test that checks.
 * - {@see testCorrectingDoesNotConfirmRoles} pins the amendment: fixing a boundary on a call the
 *   pipeline refused to publish must not promote the untouched guessed roles around it.
 *
 * **Never calls `claimNextQueued()`** — it takes the oldest QUEUED row in the whole table and can strand
 * a real administrator's pending upload. Fixtures are inserted with explicit statuses and removed by
 * their own public id; nothing is deleted in bulk.
 */
final class ReviewConversationServiceTest extends Unit
{
    private const RAW_TRANSCRIPT = 'Yes. For pikup or delivery?';

    private ConnectionInterface $connection;
    private ReviewConversationService $service;
    private DbTranscriptionJobRepository $jobs;
    private DbSegmentRevisionRepository $revisions;
    private EffectiveConversationReader $effective;
    private int $adminId;

    /** @var list<string> */
    private array $createdPublicIds = [];

    /** @var list<string> */
    private array $createdUsernames = [];

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $clock = new SystemClock();
        $decoder = new SpeakerSegmentsDecoder();

        $this->jobs = new DbTranscriptionJobRepository($this->connection, $clock);
        $this->revisions = new DbSegmentRevisionRepository($this->connection, $clock);
        $this->effective = new EffectiveConversationReader($decoder);
        $this->service = new ReviewConversationService(
            $this->jobs,
            $this->revisions,
            $decoder,
            new TransactionalRunner($this->connection),
        );

        $username = '__kf_reviewsvc_' . bin2hex(random_bytes(4)) . '__';
        $this->createdUsernames[] = $username;
        $this->adminId = (new DbAdminUserRepository($this->connection, $clock))
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

    // ---------------------------------------------------------------- the immutability guarantee

    /** The guarantee everything else rests on. */
    public function testNoOperationEverTouchesTheMachineResult(): void
    {
        $publicId = $this->seed();
        $before = $this->rawColumns($publicId);

        // Every operation type, each one valid. The merge undoes the split, which is the only merge the
        // rule permits here: both halves inherit SPEAKER_00, whereas the fixture's two original turns
        // are different diarization speakers and must stay unmergeable.
        $this->service->split($publicId, $this->adminId, 0, 5, $this->version($publicId));
        $this->service->mergeWithNext($publicId, $this->adminId, 0, $this->version($publicId));
        $this->service->moveToAgent($publicId, $this->adminId, 0, $this->version($publicId));
        // Keeps a turn on each side, which confirmation requires.
        $this->service->moveToCustomer($publicId, $this->adminId, 1, $this->version($publicId));
        $this->service->editText($publicId, $this->adminId, 0, 'Yes. For pickup', $this->version($publicId));
        $this->service->confirmRoles($publicId, $this->adminId, $this->version($publicId));
        $this->service->revert($publicId, $this->adminId, $this->version($publicId));

        $this->assertSame($before, $this->rawColumns($publicId), 'A correction must not alter a machine column.');
        $this->assertSame(self::RAW_TRANSCRIPT, $before['transcript']);
        $this->assertStringContainsString('pikup', (string) $before['transcript'], 'The mishearing survives.');
    }

    // ---------------------------------------------------------------- operations

    public function testSplittingThenMovingCorrectsTheAttribution(): void
    {
        $publicId = $this->seed();

        $this->service->split($publicId, $this->adminId, 0, 5, $this->version($publicId));
        $this->service->moveToAgent($publicId, $this->adminId, 1, $this->version($publicId));

        $turns = $this->reviewedTurns($publicId);

        // Splitting cuts, it does not reword: the mishearing survives into both halves untouched.
        $this->assertSame('Yes.', $turns[0]->text);
        $this->assertSame('For pikup', $turns[1]->text);
        $this->assertSame('CUSTOMER', $turns[0]->role->value);
        $this->assertSame('AGENT', $turns[1]->role->value);
    }

    /** Corrected wording lives in the reviewed layer and nowhere else. */
    public function testEditedTextAppearsOnlyInTheReviewedLayer(): void
    {
        $publicId = $this->seed();

        $this->service->editText($publicId, $this->adminId, 0, 'Yes. For pickup or delivery?', $this->version($publicId));

        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);
        $this->assertSame(self::RAW_TRANSCRIPT, $job->transcript, 'The raw transcription keeps "pikup".');
        $this->assertStringContainsString('For pickup', (string) $job->reviewedSegmentsJson);
    }

    public function testRevertingRestoresTheMachineConversationAndClearsConfirmation(): void
    {
        $publicId = $this->seed();

        // Both roles have to end up populated: confirmation asserts a two-sided split, so the service
        // refuses one where every turn sits on the same side.
        $this->service->moveToAgent($publicId, $this->adminId, 0, $this->version($publicId));
        $this->service->moveToCustomer($publicId, $this->adminId, 1, $this->version($publicId));
        $this->service->confirmRoles($publicId, $this->adminId, $this->version($publicId));
        $this->service->revert($publicId, $this->adminId, $this->version($publicId));

        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);
        $this->assertFalse($job->isReviewed());
        $this->assertNull($job->rolesConfirmedAt, 'A revert withdraws the human confirmation too.');
        $this->assertNull($job->reviewedAgentText);
    }

    public function testAnInvalidCorrectionIsRefusedAndWritesNothing(): void
    {
        $publicId = $this->seed();

        $rejected = false;

        try {
            // Merging across different speakers is exactly the mistake this feature exists to correct.
            $this->service->mergeWithNext($publicId, $this->adminId, 0, $this->version($publicId));
        } catch (ReviewRejected) {
            $rejected = true;
        }

        $this->assertTrue($rejected);

        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);
        $this->assertFalse($job->isReviewed(), 'A refused correction leaves no reviewed layer.');
        $this->assertSame(0, $this->revisions->countForJob($job->id), 'And no audit row.');
    }

    public function testOnlyCompletedJobsCanBeCorrected(): void
    {
        $publicId = $this->seed(status: 'PROCESSING');

        $this->expectException(ReviewRejected::class);
        $this->service->moveToAgent($publicId, $this->adminId, 0, 0);
    }

    public function testAnUnknownJobIsRefusedTheSameWayAsAnUncorrectableOne(): void
    {
        $this->expectException(ReviewRejected::class);
        $this->service->moveToAgent(bin2hex(random_bytes(16)), $this->adminId, 0, 0);
    }

    // ---------------------------------------------------------------- confirmation

    /**
     * The amendment, pinned.
     *
     * A structural correction on a NEEDS_REVIEW call must not publish role labels for the turns nobody
     * looked at. The reviewed layer exists, the role columns stay NULL, and the view still renders
     * neutral names.
     */
    public function testCorrectingDoesNotConfirmRoles(): void
    {
        $publicId = $this->seed(separation: 'NEEDS_REVIEW', agentText: null, customerText: null);

        $this->service->split($publicId, $this->adminId, 0, 5, $this->version($publicId));

        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);
        $this->assertTrue($job->isReviewed());
        $this->assertNull($job->rolesConfirmedAt);
        $this->assertFalse($job->rolesConfirmed());
        $this->assertNull($job->reviewedAgentText, 'Role text stays unpublished until somebody confirms.');
        $this->assertNull($job->reviewedCustomerText);

        // And the existing publish gate does the rest, unchanged.
        $effective = $this->effective->for($job);
        $view = ConversationView::from(
            $job->speakerSeparationStatus,
            $effective->utterances,
            null,
            $effective->hasSeparatedText(),
        );

        $this->assertFalse($view->rolesPublished);
        $this->assertSame('Speaker 1', $view->turns[0]->label);
    }

    public function testConfirmingPublishesTheDerivedRoleText(): void
    {
        $publicId = $this->seed(separation: 'NEEDS_REVIEW', agentText: null, customerText: null);

        $this->service->split($publicId, $this->adminId, 0, 5, $this->version($publicId));
        $this->service->moveToAgent($publicId, $this->adminId, 1, $this->version($publicId));
        $this->service->confirmRoles($publicId, $this->adminId, $this->version($publicId));

        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);
        $this->assertTrue($job->rolesConfirmed());
        $this->assertSame('Yes.', $job->reviewedCustomerText);
        // Two AGENT turns, joined with "\n" — exactly how SpeakerSeparationService::textFor() assembles
        // the machine's own columns. Nothing is reworded, so "pikup" is still what was said.
        $this->assertSame("For pikup\nor delivery?", $job->reviewedAgentText);

        // Derived, never authored: the stored columns equal a fresh derivation from the stored JSON.
        $turns = $this->reviewedTurnsCollection($publicId);
        $this->assertSame($turns->textFor(\App\AudioToText\Domain\SpeakerRole::AGENT), $job->reviewedAgentText);
    }

    /** A call the machine already published needs no extra ceremony. */
    public function testAnAlreadyPublishedSplitIsConfirmedWithoutAnyFurtherAction(): void
    {
        $job = $this->jobs->findByPublicId($this->seed());

        $this->assertNotNull($job);
        $this->assertNull($job->rolesConfirmedAt);
        $this->assertTrue($job->rolesConfirmed(), 'The pipeline cleared its own gates.');
    }

    public function testConfirmingTwiceIsRefused(): void
    {
        $publicId = $this->seed(separation: 'NEEDS_REVIEW', agentText: null, customerText: null);

        $this->service->confirmRoles($publicId, $this->adminId, $this->version($publicId));

        $this->expectException(ReviewRejected::class);
        $this->service->confirmRoles($publicId, $this->adminId, $this->version($publicId));
    }

    // ---------------------------------------------------------------- audit and locking

    public function testEveryAcceptedOperationIsAudited(): void
    {
        $publicId = $this->seed();

        $this->service->split($publicId, $this->adminId, 0, 5, $this->version($publicId));
        $this->service->moveToAgent($publicId, $this->adminId, 1, $this->version($publicId));
        $this->service->editText($publicId, $this->adminId, 1, 'For pickup', $this->version($publicId));
        $this->service->confirmRoles($publicId, $this->adminId, $this->version($publicId));
        $this->service->revert($publicId, $this->adminId, $this->version($publicId));

        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);

        $history = $this->revisions->forJob($job->id);
        $operations = [];
        foreach ($history as $revision) {
            $operations[] = $revision->operation;
        }

        $this->assertSame([
            ReviewOperation::Split,
            ReviewOperation::Move,
            ReviewOperation::EditText,
            ReviewOperation::ConfirmRoles,
            ReviewOperation::Revert,
        ], $operations);

        // The first revision holds the machine's own segments — the trail is complete back to origin.
        $this->assertStringContainsString('pikup', $history[0]->segmentsJson);
        $this->assertSame($this->createdUsernames[0], $history[0]->editedByUsername);
    }

    public function testAStaleVersionIsRefusedAndLeavesNoAuditRow(): void
    {
        $publicId = $this->seed();
        $stale = $this->version($publicId);

        $this->service->moveToAgent($publicId, $this->adminId, 0, $stale);

        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);
        $revisionsAfterFirst = $this->revisions->countForJob($job->id);

        $conflicted = false;

        try {
            // The second tab still believes the version it read on load.
            $this->service->moveToCustomer($publicId, $this->adminId, 0, $stale);
        } catch (ReviewConflict) {
            $conflicted = true;
        }

        $this->assertTrue($conflicted);
        $this->assertSame(
            $revisionsAfterFirst,
            $this->revisions->countForJob($job->id),
            'The rollback must take the revision with it — no orphan audit row.',
        );
    }

    public function testConfirmationIsAlsoVersionGuarded(): void
    {
        $publicId = $this->seed(separation: 'NEEDS_REVIEW', agentText: null, customerText: null);
        $stale = $this->version($publicId);

        $this->service->split($publicId, $this->adminId, 0, 5, $stale);

        $this->expectException(ReviewConflict::class);
        $this->service->confirmRoles($publicId, $this->adminId, $stale);
    }

    // ---------------------------------------------------------------- helpers

    private function version(string $publicId): int
    {
        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);

        return $job->reviewCount;
    }

    /**
     * @return list<\App\AudioToText\Domain\Speaker\ReviewedTurn>
     */
    private function reviewedTurns(string $publicId): array
    {
        return $this->reviewedTurnsCollection($publicId)->turns;
    }

    private function reviewedTurnsCollection(string $publicId): \App\AudioToText\Domain\Speaker\ReviewedConversationTurns
    {
        $job = $this->jobs->findByPublicId($publicId);
        $this->assertNotNull($job);

        return \App\AudioToText\Domain\Speaker\ReviewedConversationTurns::fromJson($job->reviewedSegmentsJson);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawColumns(string $publicId): array
    {
        /** @var array<string, mixed> $row */
        $row = (array) (new Query($this->connection))
            ->select(['transcript', 'speaker_segments', 'agent_text', 'customer_text'])
            ->from('{{%audio_transcription_jobs}}')
            ->where(['public_id' => $publicId])
            ->one();

        return $row;
    }

    private function seed(
        string $status = 'COMPLETED',
        string $separation = 'COMPLETED',
        ?string $agentText = 'or delivery?',
        ?string $customerText = 'Yes. For pikup',
    ): string {
        $publicId = bin2hex(random_bytes(16));
        $this->createdPublicIds[] = $publicId;

        $segments = [
            [
                'start_ms' => 0, 'end_ms' => 2000, 'speaker' => 'SPEAKER_00',
                'role' => 'CUSTOMER', 'text' => 'Yes. For pikup', 'confidence' => 0.9,
            ],
            [
                'start_ms' => 2100, 'end_ms' => 3000, 'speaker' => 'SPEAKER_01',
                'role' => 'AGENT', 'text' => 'or delivery?', 'confidence' => 0.9,
            ],
        ];

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => $publicId,
            'uploaded_by_admin_id' => $this->adminId,
            'status' => $status,
            'processing_stage' => $status === 'COMPLETED' ? 'COMPLETED' : 'TRANSCRIBING',
            'original_filename' => 'review-service-fixture.wav',
            'transcript' => self::RAW_TRANSCRIPT,
            'agent_text' => $agentText,
            'customer_text' => $customerText,
            'speaker_segments' => json_encode($segments),
            'speaker_separation_status' => $separation,
            'created_at' => '2026-08-31 10:00:00',
            'completed_at' => $status === 'COMPLETED' ? '2026-08-31 10:05:00' : null,
        ])->execute();

        return $publicId;
    }
}
