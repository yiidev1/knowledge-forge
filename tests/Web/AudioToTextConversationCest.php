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

use function bin2hex;
use function gmdate;
use function json_encode;
use function random_bytes;

/**
 * The conversation-only page, and the View action that now leads to it.
 *
 * The page renders no speaker rule of its own — it calls the same reader and the same
 * `ConversationView` the detail page calls. What these tests pin is that the *same answers* come out:
 * a NEEDS_REVIEW call is neutral here too, a confirmation publishes here too, and a corrected
 * conversation shows the correction rather than the machine's original. If this page ever grew its own
 * interpretation, those are the assertions that would catch it.
 */
final class AudioToTextConversationCest
{
    private const ADMIN = '__kf_a2t_conv_admin__';
    private const PASSWORD = 'AudioConvPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';
    private const TRANSCRIPT = 'Yes. For pikup or delivery?';

    private ConnectionInterface $connection;
    private int $adminId;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $this->adminId = (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ---------------------------------------------------------------- the View action

    public function theConversionsListViewActionOpensTheConversationPage(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/jobs');
        $I->seeElement('a[href="/audio-to-text/job/' . $publicId . '/conversation"]');

        $I->click('View');
        $I->seeCurrentUrlEquals('/audio-to-text/job/' . $publicId . '/conversation');
    }

    /** Nothing to read, so the row leads on to the page that explains why. */
    public function aJobWithNoConversationRedirectsToTheFullDetailPage(WebTester $I): void
    {
        $queued = $this->seed(status: 'QUEUED');

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $queued . '/conversation');
        $I->seeCurrentUrlEquals('/audio-to-text/job/' . $queued);
        $I->see('Queued');
    }

    public function aCompletedJobWithNoSpeakerSegmentsAlsoRedirects(WebTester $I): void
    {
        $publicId = $this->seed(separation: 'NOT_SUPPORTED', segments: null, agentText: null, customerText: null);

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/conversation');
        $I->seeCurrentUrlEquals('/audio-to-text/job/' . $publicId);
    }

    // ---------------------------------------------------------------- access

    public function aGuestIsSentToLogin(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/audio-to-text/job/' . $this->seed() . '/conversation');
        $I->seeInCurrentUrl('/login');
    }

    public function anAgentSessionCannotReachTheConversationPage(WebTester $I): void
    {
        $publicId = $this->seed();

        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/agent/login');
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/conversation');
        $I->dontSeeElement('.a2t-chat');
    }

    public function anUnknownJobIsNotFound(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . bin2hex(random_bytes(16)) . '/conversation');
        $I->seeResponseCodeIs(404);
    }

    // ---------------------------------------------------------------- what the page shows

    public function aPublishedConversationShowsCustomerAndAgentBubbles(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/conversation');

        $I->see('Customer', '.a2t-turn--left .a2t-turn__who');
        $I->see('Agent', '.a2t-turn--right .a2t-turn__who');
        $I->see('Yes. For pikup');
        $I->seeElement('.a2t-chat__scroll .a2t-thread');
    }

    /** Conversation-only: everything the detail page wraps around it is deliberately absent. */
    public function theConversationPageShowsNothingButTheConversation(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/conversation');

        $I->dontSee('Complete transcript');
        $I->dontSeeElement('.a2t-transcript');
        $I->dontSeeElement('.a2t-meta');
        $I->dontSeeElement('.a2t-split');
        $I->dontSeeElement('a[href*="/download"]');
    }

    public function aNeedsReviewConversationStaysNeutralUntilConfirmed(WebTester $I): void
    {
        $publicId = $this->seed(separation: 'NEEDS_REVIEW', agentText: null, customerText: null);

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/conversation');
        $I->see('Speaker 1');
        $I->dontSee('Agent', '.a2t-turn__who');

        // Confirm through the existing review page — this page has no correction system of its own.
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->click('Confirm speaker roles');

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/conversation');
        $I->dontSee('Speaker 1');
        $I->see('Customer', '.a2t-turn--left .a2t-turn__who');
        $I->see('Agent', '.a2t-turn--right .a2t-turn__who');
    }

    /** The reviewed layer wins here exactly as it does on the detail page. */
    public function aCorrectedConversationShowsTheCorrection(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->submitForm(
            'form[action="/audio-to-text/job/' . $publicId . '/review/turn/0/text"]',
            ['text' => 'Yes. For pickup'],
        );

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/conversation');
        $I->see('Yes. For pickup');
        $I->dontSee('Yes. For pikup');
        $I->see('edited');
    }

    // ---------------------------------------------------------------- the way out

    public function theHeaderLinksToTheFullDetailPageAndTheReviewPage(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/conversation');

        $I->seeElement('.a2t-chat__actions a[href="/audio-to-text/job/' . $publicId . '"]');
        $I->seeElement('.a2t-chat__actions a[href="/audio-to-text/job/' . $publicId . '/review"]');

        $I->click('Full conversion details');
        $I->seeCurrentUrlEquals('/audio-to-text/job/' . $publicId);
        $I->see('Complete transcript');
    }

    public function theBreadcrumbLeadsBackToTheConversionsList(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/conversation');
        $I->seeElement('.breadcrumbs a[href="/audio-to-text/jobs"]');
    }

    // ---------------------------------------------------------------- the guarantee

    public function readingTheConversationChangesNothing(WebTester $I): void
    {
        $publicId = $this->seed();
        $before = $this->rawColumns($publicId);

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/conversation');
        $I->amOnPage('/audio-to-text/job/' . $publicId);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/conversation');

        Assert::assertSame($before, $this->rawColumns($publicId), 'Reading a conversation writes nothing.');
    }

    // ---------------------------------------------------------------- helpers

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
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
        ?string $segments = 'default',
    ): string {
        $publicId = bin2hex(random_bytes(16));

        $rows = [
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
            'processing_stage' => $status === 'COMPLETED' ? 'COMPLETED' : 'QUEUED',
            'original_filename' => 'conversation-fixture.wav',
            'transcript' => $status === 'COMPLETED' ? self::TRANSCRIPT : null,
            'agent_text' => $agentText,
            'customer_text' => $customerText,
            'speaker_segments' => $segments === null ? null : (string) json_encode($rows),
            'speaker_separation_status' => $status === 'COMPLETED' ? $separation : null,
            'speaker_role_confidence' => $separation === 'COMPLETED' ? 0.9 : 0.08,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'completed_at' => $status === 'COMPLETED' ? gmdate('Y-m-d H:i:s') : null,
        ])->execute();

        return $publicId;
    }

    /** Scoped to this suite's own administrator, never a blanket delete. */
    private function cleanup(): void
    {
        $adminIds = (new Query($this->connection))
            ->select('id')
            ->from('{{%admin_users}}')
            ->where(['username' => self::ADMIN])
            ->column();

        if ($adminIds !== []) {
            // Jobs before administrators: the uploader foreign key is RESTRICT.
            $this->connection->createCommand()
                ->delete('{{%audio_transcription_jobs}}', ['uploaded_by_admin_id' => $adminIds])
                ->execute();
        }

        IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => self::ADMIN]);
    }
}
