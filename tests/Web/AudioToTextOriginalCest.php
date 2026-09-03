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
 * The machine's own transcript, through the browser.
 *
 * The guarantee under test is the one the page exists for: no amount of correcting changes what it
 * shows. Each case corrects the conversation through the real endpoints and then asserts the original
 * page still prints the machine's words, its structure and — the subtlest of the three — its own
 * verdict on whether the roles may be named at all.
 */
final class AudioToTextOriginalCest
{
    private const ADMIN = '__kf_a2t_original_admin__';
    private const PASSWORD = 'AudioOriginalPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    private const MACHINE_FIRST = 'we have lo mein';
    private const MACHINE_SECOND = 'or delivery?';

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

    // ---------------------------------------------------------------- access

    public function aGuestIsSentToLogin(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/audio-to-text/job/' . $this->seed() . '/original');
        $I->seeInCurrentUrl('/login');
    }

    public function anUnknownJobIsNotFound(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . bin2hex(random_bytes(16)) . '/original');
        $I->seeResponseCodeIs(404);
    }

    /** Nothing the machine produced to show, so the detail page explains why instead. */
    public function anIncompleteJobIsSentToTheDetailPage(WebTester $I): void
    {
        $publicId = $this->seed(status: 'PROCESSING');

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/original');
        $I->seeCurrentUrlEquals('/audio-to-text/job/' . $publicId);
    }

    // ---------------------------------------------------------------- the guarantee

    /** A. Correcting the wording leaves the machine's own transcript alone. */
    public function anEditedConversationStillShowsTheMachineWording(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->submitForm(
            'form[action="/audio-to-text/job/' . $publicId . '/review/turn/0/text"]',
            ['text' => 'we have THE lo mein'],
        );
        $I->see('Wording corrected.');

        // D. The correction page shows the correction...
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->see('we have THE lo mein');

        // A. ...and the original page does not.
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/original');
        $I->seeResponseCodeIsSuccessful();
        $I->see(self::MACHINE_FIRST);
        $I->dontSee('we have THE lo mein');
    }

    /** B. Structure too: a merge changes the turn count on /review and never here. */
    public function aMergedConversationStillShowsTheMachineStructure(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/original');
        $I->seeNumberOfElements('.a2t-turn', 2);

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->submitForm(
            'form[action="/audio-to-text/job/' . $publicId . '/review/turn/0/merge"]',
            ['direction' => 'next'],
        );
        $I->see('Turns joined.');

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->seeNumberOfElements('.a2t-turn', 1);

        // The machine's own two turns, still two.
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/original');
        $I->seeNumberOfElements('.a2t-turn', 2);
        $I->see(self::MACHINE_FIRST);
        $I->see(self::MACHINE_SECOND);
    }

    /**
     * C. The subtle one: a human confirmation publishes the *reviewed* layer, never this one.
     *
     * The machine returned NEEDS_REVIEW, so it never established who the agent was. After an
     * administrator confirms, /review names the roles and this page must still not — it reports what
     * the machine concluded, and the machine concluded nothing.
     */
    public function aHumanConfirmationNeverPublishesTheMachineLayer(WebTester $I): void
    {
        $publicId = $this->seed(separation: 'NEEDS_REVIEW', agentText: null, customerText: null);

        $this->signIn($I);

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/original');
        // Asserted on the speaker labels themselves: the page's own copy explains that the system
        // could not tell which speaker is the agent, so a bare page-wide search for the word matches
        // that sentence rather than a claim about a turn.
        $I->see('Speaker 1', '.a2t-turn__who');
        $I->dontSee('Agent', '.a2t-turn__who');

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->click('Confirm speaker roles');
        $I->see('Speaker roles confirmed.');

        // The correction page now names them...
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->see('Roles confirmed by');

        // ...and the machine's own page still does not.
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/original');
        $I->see('Speaker 1', '.a2t-turn__who');
        $I->see('Speaker 2', '.a2t-turn__who');
        $I->dontSee('Agent', '.a2t-turn__who');
        $I->dontSee('Roles confirmed by');
        Assert::assertSame(
            'NEEDS_REVIEW',
            (string) $this->rawColumn($publicId, 'speaker_separation_status'),
            'The machine verdict is never rewritten.',
        );
    }

    /** A machine result that published its own roles keeps naming them here. */
    public function aMachinePublishedSplitNamesItsRoles(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/original');
        $I->see('Customer', '.a2t-turn__who');
        $I->see('Agent', '.a2t-turn__who');
        $I->dontSee('Speaker 1', '.a2t-turn__who');
    }

    // ---------------------------------------------------------------- it is read-only

    public function thePageOffersNoCorrectionControlsAtAll(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/original');

        $I->dontSeeElement('[data-a2t-edit]');
        $I->dontSeeElement('[data-a2t-grip]');
        $I->dontSeeElement('[data-a2t-tools]');
        $I->dontSeeElement('[data-a2t-merge-controls]');
        $I->dontSeeElement('[data-a2t-confirm-form]');
        $I->dontSeeElement('form[action*="/review/"]');
        $I->dontSee('Confirm speaker roles');
        $I->dontSee('Discard all corrections');
        // Read-only, so the page itself carries no form. Scoped to the chat: the admin layout's own
        // sign-out form is on every page and is not this page's content.
        $I->dontSeeElement('.a2t-chat form');
    }

    /** The comparison is the point, so the way to the current version is on the page. */
    public function thePageLinksToTheCurrentVersionAndTheConversion(WebTester $I): void
    {
        $publicId = $this->seed();

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/original');

        $I->seeElement('a[href="/audio-to-text/job/' . $publicId . '/review"]');
        $I->see('Current version');
        $I->seeElement('a[href="/audio-to-text/job/' . $publicId . '"]');
        $I->see('Full conversion details');
        // "Back to conversation" belongs to the review page; this one offers the current version.
        $I->dontSee('Back to conversation');
    }

    /** Q. Transcript text is data, never markup — including here. */
    public function machineTextIsEscaped(WebTester $I): void
    {
        $publicId = $this->seed(segmentText: '<script>alert(1)</script>');

        $this->signIn($I);
        $I->amOnPage('/audio-to-text/job/' . $publicId . '/original');

        $source = $I->grabPageSource();
        Assert::assertStringNotContainsString('<script>alert(1)</script>', $source);
        Assert::assertStringContainsString('&lt;script&gt;', $source);
    }

    // ---------------------------------------------------------------- helpers

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
    }

    private function seed(
        string $status = 'COMPLETED',
        string $separation = 'COMPLETED',
        ?string $agentText = 'or delivery?',
        ?string $customerText = 'we have lo mein',
        ?string $segmentText = null,
    ): string {
        $publicId = bin2hex(random_bytes(16));

        $segments = [
            [
                'start_ms' => 0, 'end_ms' => 2000, 'speaker' => 'SPEAKER_00',
                'role' => 'CUSTOMER', 'text' => $segmentText ?? self::MACHINE_FIRST, 'confidence' => 0.9,
            ],
            [
                'start_ms' => 2100, 'end_ms' => 3000, 'speaker' => 'SPEAKER_01',
                'role' => 'AGENT', 'text' => self::MACHINE_SECOND, 'confidence' => 0.9,
            ],
        ];

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', [
            'public_id' => $publicId,
            'uploaded_by_admin_id' => $this->adminId,
            'status' => $status,
            'processing_stage' => $status === 'COMPLETED' ? 'COMPLETED' : 'TRANSCRIBING',
            'original_filename' => 'original-web-fixture.wav',
            'transcript' => self::MACHINE_FIRST . ' ' . self::MACHINE_SECOND,
            'agent_text' => $agentText,
            'customer_text' => $customerText,
            'speaker_segments' => (string) json_encode($segments),
            'speaker_separation_status' => $separation,
            'speaker_role_confidence' => $separation === 'COMPLETED' ? 0.9 : 0.08,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'completed_at' => $status === 'COMPLETED' ? gmdate('Y-m-d H:i:s') : null,
        ])->execute();

        return $publicId;
    }

    private function rawColumn(string $publicId, string $column): mixed
    {
        return (new Query($this->connection))
            ->select($column)
            ->from('{{%audio_transcription_jobs}}')
            ->where(['public_id' => $publicId])
            ->scalar();
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
            $this->connection->createCommand()
                ->delete('{{%audio_transcription_jobs}}', ['uploaded_by_admin_id' => $adminIds])
                ->execute();
        }

        IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => self::ADMIN]);
    }
}
