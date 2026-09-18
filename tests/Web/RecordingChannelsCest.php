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

use function dirname;
use function file_get_contents;
use function http_build_query;
use function urlencode;

/**
 * The separated-channel test page, against the real served application.
 *
 * **Nothing here reaches the external recording API.** Every test either stops before a request is made
 * (validation, a bare page load) or asks for the local fixture path explicitly. That is not a
 * limitation of the suite — this machine is not on the provider's IP allowlist, and working around
 * that is exactly what the tool must not do.
 *
 * What is pinned here is the behaviour a template could quietly get wrong: that the page states its
 * caller/callee caveat, that the fixture path never engages unless asked, and that the download endpoint
 * refuses every shape of bad input before it streams a byte.
 */
final class RecordingChannelsCest
{
    private const ADMIN = '__kf_chan_admin__';
    private const PASSWORD = 'ChannelTestPassw0rd!secure';
    private const SESSION_COOKIE = 'KFSESSID';

    private const PAGE = '/admin/order58/test-recording-channels';
    private const DOWNLOAD = '/admin/order58/test-recording-channels/download';

    private const RECORDING_ID = '22342359';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::ADMIN, (new NativePasswordHasher())->hash(self::PASSWORD));
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ authorisation

    public function aGuestIsSentToLoginFromThePage(WebTester $I): void
    {
        $I->amOnPage(self::PAGE);
        $I->seeCurrentUrlEquals('/login');
    }

    public function aGuestCannotReachTheDownloadEndpoint(WebTester $I): void
    {
        $I->amOnPage(self::DOWNLOAD . '?' . $this->query());
        $I->seeCurrentUrlEquals('/login');
    }

    // ------------------------------------------------------------------ the existing tool is untouched

    /**
     * **The promise the whole feature was built around**, checked through HTTP rather than only in source.
     */
    public function theExistingRecordingApiPageStillWorks(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage('/admin/order58/test-recording-apis');
        $I->seeResponseCodeIs(200);
        $I->see('Send Fetch Recording Request');
        // Its two forms are unchanged: no channel control has appeared on it.
        $I->dontSee('Channel');
        $I->dontSee('Callee');
    }

    // ------------------------------------------------------------------ the page

    public function theFormOffersAllThreeChannels(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->seeResponseCodeIs(200);
        $I->seeElement('input[name="channel"][value="mixed"]');
        $I->seeElement('input[name="channel"][value="caller"]');
        $I->seeElement('input[name="channel"][value="callee"]');
    }

    /** The three filenames are shown before anything is requested, so the convention is visible. */
    public function theGeneratedFilenamesAreShownForEachChannel(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->see('22342359.wav');
        $I->see('22342359-caller.wav');
        $I->see('22342359-callee.wav');
    }

    /**
     * The caveat is not buried. It is the single most important thing on the page, because a guessed URL
     * that answers 200 with the mixed file would read as success.
     */
    public function thePageStatesThatCallerAndCalleeAreUnconfirmed(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->see('Caller and callee retrieval is not production-ready');
        $I->see('request format unconfirmed');
    }

    /** Merchant ID is collected but not sent, and the page says so rather than leaving it ambiguous. */
    public function thePageExplainsThatMerchantIdIsNotSent(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->seeElement('input[name="merchant_id"]');
        $I->see('not');
        $I->see('sent to the API');
    }

    /** A bare page load runs nothing at all. */
    public function loadingThePageMakesNoRequest(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->dontSee('Result');
    }

    // ------------------------------------------------------------------ validation stops before the wire

    public function anInvalidRecordingIdIsRefusedWithoutARequest(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?submitted=1&recording_id=abc&merchant_id=871&channel=mixed'
            . '&time=2026-03-11&company=SWCC&name=test');

        $I->see('Nothing was sent');
        $I->see('Recording ID is required and must be digits only');
    }

    /**
     * A traversal attempt is refused before it can become a filename or a URL.
     *
     * The rejected value IS echoed back into the form field — escaped, and on purpose, so it can be
     * corrected. What must not happen is it becoming a `.wav` name or a request, so that is what is
     * asserted rather than its mere absence from the page.
     */
    public function aPathTraversalAttemptIsRefusedWithoutARequest(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?submitted=1&recording_id=' . urlencode('../../etc/passwd')
            . '&merchant_id=871&channel=mixed&time=2026-03-11&company=SWCC&name=test');

        $I->see('Nothing was sent');
        $I->see('Recording ID is required and must be digits only');
        // Never built into a filename, and no request was constructed from it.
        $I->dontSee('etc/passwd.wav');
        $I->dontSee('api/external/recording');
    }

    // ------------------------------------------------------------------ fixtures

    /**
     * The fixture path answers only when asked for on this exact request.
     *
     * Its absence is the default everywhere; see `RecordingChannelIsolationTest` for the environment
     * half of the same rule.
     */
    public function theFixturePathAnswersOnlyWhenExplicitlyRequested(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?submitted=1&' . $this->query() . '&source=fixture');

        $I->seeResponseCodeIs(200);
        $I->see('LOCAL FIXTURE');
        $I->see('Valid WAV');
    }

    /** Each channel resolves to its own fixture, so a wrong-channel answer would be visible. */
    public function eachChannelResolvesToItsOwnFixture(WebTester $I): void
    {
        $this->signIn($I);

        foreach (['mixed' => '22342359.wav', 'caller' => '22342359-caller.wav', 'callee' => '22342359-callee.wav'] as $channel => $file) {
            $I->amOnPage(self::PAGE . '?submitted=1&' . $this->query($channel) . '&source=fixture');

            $I->see($file);
            $I->see('LOCAL FIXTURE');
        }
    }

    public function aFixtureThatDoesNotExistIsReportedRatherThanInvented(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?submitted=1&recording_id=99999999&merchant_id=871&channel=mixed'
            . '&time=2026-03-11&company=SWCC&name=test&source=fixture');

        $I->see('No fixture exists');
    }

    // ------------------------------------------------------------------ download and play

    /**
     * The bytes served are the caller fixture's, not the mixed one's.
     *
     * Asserted on the body rather than on a header, and deliberately so: a `Content-Disposition` naming
     * the caller file proves only what the endpoint *claims*. Comparing the bytes proves which channel
     * was actually served, which is the thing that would go wrong silently.
     */
    public function theDownloadServesExactlyTheRequestedChannelsBytes(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::DOWNLOAD . '?' . $this->query('caller') . '&source=fixture');

        $I->seeResponseCodeIs(200);
        Assert::assertSame($this->fixture('22342359-caller.wav'), $I->grabPageSource());
        Assert::assertNotSame($this->fixture('22342359.wav'), $I->grabPageSource(), 'the mixed file must not be served for caller');
    }

    /** The Play button uses the same endpoint inline, so `<audio>` reads it from a same-origin URL. */
    public function theInlineDispositionServesThePlayButton(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::DOWNLOAD . '?' . $this->query('callee') . '&source=fixture&disposition=inline');

        $I->seeResponseCodeIs(200);
        Assert::assertSame($this->fixture('22342359-callee.wav'), $I->grabPageSource());
    }

    public function theDownloadRefusesAnUnknownChannel(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::DOWNLOAD . '?' . $this->query() . '&channel=sideways&source=fixture');

        $I->seeResponseCodeIs(400);
        $I->see('unknown channel');
    }

    public function theDownloadRefusesAnInvalidRecordingId(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::DOWNLOAD . '?recording_id=abc&merchant_id=871&channel=mixed'
            . '&time=2026-03-11&company=SWCC&name=test&source=fixture');

        $I->seeResponseCodeIs(400);
        $I->see('nothing was sent to the API');
    }

    /** A traversal attempt never becomes a path: it is refused by validation first. */
    public function theDownloadRefusesAPathTraversalAttempt(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::DOWNLOAD . '?recording_id=' . urlencode('../../../etc/passwd')
            . '&merchant_id=871&channel=mixed&time=2026-03-11&company=SWCC&name=test&source=fixture');

        $I->seeResponseCodeIs(400);
        $I->dontSee('root:');
    }

    // ------------------------------------------------------------------ helpers

    private function fixture(string $file): string
    {
        return (string) file_get_contents(
            dirname(__DIR__) . '/_data/recording-channels/' . $file,
        );
    }

    private function query(string $channel = 'mixed'): string
    {
        return http_build_query([
            'recording_id' => self::RECORDING_ID,
            'merchant_id' => '871',
            'channel' => $channel,
            'time' => '2026-03-11',
            'company' => 'SWCC',
            'name' => 'test',
        ]);
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup($this->connection, '{{%admin_users}}', ['username' => self::ADMIN]);
    }

    private function signIn(WebTester $I): void
    {
        $I->resetCookie(self::SESSION_COOKIE);
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::ADMIN, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }
}
