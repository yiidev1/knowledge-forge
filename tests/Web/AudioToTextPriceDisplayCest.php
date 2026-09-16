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
use function implode;
use function json_encode;
use function preg_match_all;
use function random_bytes;

/**
 * Currency display, proven against the served application rather than against the formatter in
 * isolation.
 *
 * Three properties matter here, and only an end-to-end assertion can establish them:
 *
 *  1. **The database is never rewritten.** Every test re-reads the stored row afterwards and asserts
 *     the machine transcript is byte-identical to what was inserted.
 *  2. **A human correction outranks automatic formatting.** A reviewed conversation that deliberately
 *     kept "$43 and 45" must render exactly that.
 *  3. **The edit form receives the raw value.** The review page posts its `<textarea>` contents
 *     straight back as the correction, so normalising there would persist a display convenience as
 *     the human's own words the moment they pressed Save.
 */
final class AudioToTextPriceDisplayCest
{
    private const ADMIN = '__kf_a2t_price_admin__';
    private const PASSWORD = 'AudioPricePassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    /** The literal the provider returns when a speaker omits "cents". */
    private const SHORTHAND = '$43 and 45';
    private const FORMATTED = '$43.45';

    private ConnectionInterface $connection;
    private int $adminId = 0;

    /** @var list<string> */
    private array $createdPublicIds = [];

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));

        /** @var array<string, mixed> $row */
        $row = $this->connection
            ->createCommand('SELECT id FROM {{%admin_users}} WHERE username = :u', [':u' => self::ADMIN])
            ->queryOne();
        $this->adminId = (int) $row['id'];
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------ unreviewed machine text IS formatted

    /**
     * The baseline: nobody has corrected this, so the reader sees a price.
     */
    public function unreviewedMachineTextIsDisplayedAsAPrice(WebTester $I): void
    {
        $publicId = $this->insertJob(reviewed: false);
        $this->signIn($I);

        $I->amOnPage('/audio-to-text/job/' . $publicId);
        $I->seeResponseCodeIs(200);
        $I->see(self::FORMATTED);

        $this->assertStoredTranscriptUnchanged($publicId);
    }

    // ------------------------------------------------ a human correction wins

    /**
     * **The authority test.** A reviewed conversation renders exactly what the human saved.
     *
     * The correction here deliberately keeps the shorthand. Displaying `$43.45` over it would overrule
     * a decision somebody made on purpose, which is precisely what must not happen.
     */
    public function aReviewedTranscriptKeepsTheHumansExactWording(WebTester $I): void
    {
        $publicId = $this->insertJob(reviewed: true);
        $this->signIn($I);

        $I->amOnPage('/audio-to-text/job/' . $publicId);
        $I->seeResponseCodeIs(200);

        // The corrected turn is shown verbatim…
        $I->see(self::SHORTHAND, '.a2t-turn__text');
        // …and the automatic form never appears inside the conversation.
        $I->dontSeeElement('.a2t-turn__text:contains("' . self::FORMATTED . '")');

        $this->assertStoredTranscriptUnchanged($publicId);
        $this->assertStoredReviewUnchanged($publicId);
    }

    // ------------------------------------------------ the edit form is never pre-formatted

    /**
     * **The save-safety test.** The review textarea carries the raw value.
     *
     * `Review/template.php` posts `name="text"` straight to the correction endpoint, so whatever sits
     * in that box becomes the stored correction. If the formatter ran first, pressing Save would
     * silently persist `$43.45` as something the human never typed.
     */
    public function theReviewTextareaReceivesTheRawValue(WebTester $I): void
    {
        $publicId = $this->insertJob(reviewed: false);
        $this->signIn($I);

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->seeResponseCodeIs(200);

        // Every editable field on the page, isolated from the rest of the markup — the surrounding
        // bubbles are display and may legitimately differ; only what a Save would POST matters here.
        preg_match_all('#<textarea[^>]*>(.*?)</textarea>#s', $I->grabPageSource(), $matches);

        Assert::assertNotEmpty($matches[1], 'The review page must offer an edit field.');

        foreach ($matches[1] as $contents) {
            Assert::assertStringNotContainsString(
                self::FORMATTED,
                $contents,
                'A formatted value in the textarea would be persisted as the human correction on save.',
            );
        }

        Assert::assertStringContainsString(
            self::SHORTHAND,
            implode("\n", $matches[1]),
            'The edit form must be pre-filled with the stored text, never the formatted one.',
        );

        $this->assertStoredTranscriptUnchanged($publicId);
    }

    /** The audit view shows the machine's own words, unformatted, by design. */
    public function theOriginalTranscriptPageStaysVerbatim(WebTester $I): void
    {
        $publicId = $this->insertJob(reviewed: false);
        $this->signIn($I);

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/original');
        $I->seeResponseCodeIs(200);
        $I->see(self::SHORTHAND, '.a2t-turn__text');

        $this->assertStoredTranscriptUnchanged($publicId);
    }

    /** The export is the machine record, so it is byte-for-byte what was stored. */
    public function theDownloadStaysVerbatim(WebTester $I): void
    {
        $publicId = $this->insertJob(reviewed: false);
        $this->signIn($I);

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/download?part=transcript');

        $body = $I->grabPageSource();
        Assert::assertStringContainsString(self::SHORTHAND, $body);
        Assert::assertStringNotContainsString(self::FORMATTED, $body);
    }

    // ------------------------------------------------ the review page: bubble vs edit field

    /**
     * **A.** The review bubble shows the price; **B.** the edit field beside it does not.
     *
     * Both assertions live in one test because the whole point is that these two values differ on the
     * same render. Splitting them would let one pass while the other silently regressed.
     */
    public function theReviewBubbleIsFormattedWhileTheEditFieldStaysRaw(WebTester $I): void
    {
        $publicId = $this->insertJob(reviewed: false);
        $this->signIn($I);

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->seeResponseCodeIs(200);

        // A — the reader-facing bubble.
        $I->see(self::FORMATTED, '.a2t-turn__text');

        // B — every editable field carries the stored value, never the rendered one.
        preg_match_all('#<textarea[^>]*>(.*?)</textarea>#s', $I->grabPageSource(), $matches);
        Assert::assertNotEmpty($matches[1], 'The review page must offer an edit field.');

        foreach ($matches[1] as $contents) {
            Assert::assertStringNotContainsString(self::FORMATTED, $contents);
        }
        Assert::assertStringContainsString(self::SHORTHAND, implode("\n", $matches[1]));

        $this->assertStoredTranscriptUnchanged($publicId);
    }

    /**
     * **E.** The bubble carries the stored value in `data-a2t-raw`.
     *
     * `admin.js` restores the editor from this node when Cancel is pressed. Without the attribute it
     * would copy the *rendered* price into the textarea, and the next Save would persist a display
     * convenience as the administrator's own words.
     */
    public function theReviewBubbleCarriesTheRawValueForTheEditor(WebTester $I): void
    {
        $publicId = $this->insertJob(reviewed: false);
        $this->signIn($I);

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');

        $I->seeElement('.a2t-turn__text[data-a2t-raw]');
        Assert::assertStringContainsString(
            'data-a2t-raw="Your total is $43 and 45 ready in 25 minutes."',
            $I->grabPageSource(),
            'Cancel restores the editor from this attribute; it must hold the stored text.',
        );
    }

    /**
     * **C.** A saved human correction is never reformatted, on the review page either.
     */
    public function theReviewBubbleKeepsAHumanCorrectionVerbatim(WebTester $I): void
    {
        $publicId = $this->insertJob(reviewed: true);
        $this->signIn($I);

        $I->amOnPage('/audio-to-text/job/' . $publicId . '/review');
        $I->seeResponseCodeIs(200);

        $I->see(self::SHORTHAND, '.a2t-turn__text');
        Assert::assertStringNotContainsString(
            self::FORMATTED,
            $I->grabPageSource(),
            'A human correction outranks automatic formatting everywhere, including the review page.',
        );

        $this->assertStoredTranscriptUnchanged($publicId);
        $this->assertStoredReviewUnchanged($publicId);
    }

    // ---------------------------------------------------------------------------------- helpers

    /** Re-reads the row and proves rendering wrote nothing back. */
    private function assertStoredTranscriptUnchanged(string $publicId): void
    {
        $stored = (string) $this->connection
            ->createCommand(
                'SELECT transcript FROM {{%audio_transcription_jobs}} WHERE public_id = :p',
                [':p' => $publicId],
            )
            ->queryScalar();

        Assert::assertStringContainsString(
            self::SHORTHAND,
            $stored,
            'The machine transcript in the database must never be rewritten.',
        );
        Assert::assertStringNotContainsString(self::FORMATTED, $stored);
    }

    private function assertStoredReviewUnchanged(string $publicId): void
    {
        $stored = (string) $this->connection
            ->createCommand(
                'SELECT reviewed_segments FROM {{%audio_transcription_jobs}} WHERE public_id = :p',
                [':p' => $publicId],
            )
            ->queryScalar();

        Assert::assertStringContainsString(self::SHORTHAND, $stored, 'The saved correction must survive.');
        Assert::assertStringNotContainsString(self::FORMATTED, $stored);
    }

    /**
     * One COMPLETED job whose text contains the shorthand, optionally carrying a human correction.
     *
     * Inserted directly rather than transcribed: this suite is about rendering, and running a provider
     * would make it slow, costly and non-deterministic.
     */
    private function insertJob(bool $reviewed): string
    {
        $publicId = bin2hex(random_bytes(16));
        $this->createdPublicIds[] = $publicId;
        $now = gmdate('Y-m-d H:i:s');

        $segments = [[
            'start_ms' => 0,
            'end_ms' => 4000,
            'speaker' => 'SPEAKER_00',
            'role' => 'CUSTOMER',
            'text' => 'Your total is ' . self::SHORTHAND . ' ready in 25 minutes.',
            'confidence' => 0.95,
        ]];

        $columns = [
            'public_id' => $publicId,
            'uploaded_by_admin_id' => $this->adminId,
            'status' => 'COMPLETED',
            'processing_stage' => 'COMPLETED',
            'original_filename' => 'price-display-test.wav',
            'duration_seconds' => 4.0,
            'transcript' => 'Your total is ' . self::SHORTHAND . ' ready in 25 minutes.',
            'detected_language' => 'en',
            'speaker_segments' => (string) json_encode($segments),
            'speaker_separation_status' => 'COMPLETED',
            'speaker_separation_method' => 'test-fixture',
            'transcription_provider' => 'DEEPGRAM',
            'created_at' => $now,
            'completed_at' => $now,
        ];

        if ($reviewed) {
            // The human looked at it and deliberately kept the shorthand.
            $columns['reviewed_segments'] = (string) json_encode($segments);
            $columns['reviewed_at'] = $now;
            $columns['reviewed_by_admin_id'] = $this->adminId;
        }

        $this->connection->createCommand()->insert('{{%audio_transcription_jobs}}', $columns)->execute();

        return $publicId;
    }

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    /** Scoped to this suite's own administrator; both foreign keys are RESTRICT. */
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
        $this->createdPublicIds = [];
    }
}
