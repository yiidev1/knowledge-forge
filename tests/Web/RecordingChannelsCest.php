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

    /**
     * The three request segments are shown before anything is requested, so the addressing is visible.
     *
     * Segments, not filenames: the channel travels in the path, and showing `.wav` names beside the
     * radios is what previously suggested the filename was the thing being sent.
     */
    public function theRequestSegmentsAreShownForEachChannel(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->see('22342359-caller');
        $I->see('22342359-callee');
        $I->dontSee('22342359-caller.wav');
    }

    /**
     * The remaining caveat is not buried: a well-formed channel request still fails for a merchant the
     * client does not generate separated files for, and a 404 read as a mapping bug sends somebody
     * hunting for a fault that is not there.
     */
    public function thePageStatesThatSeparatedChannelsNeedAListedMerchant(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->see("Separated channels exist only for the merchants on the client's list");
        $I->see('listed merchants only');
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

        $I->dontSee('Bytes received');
    }

    /** Nothing resembling a credential reaches the rendered page — there is none to leak. */
    public function noCredentialAppearsInTheRenderedHtml(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?load_calls=1&account_id=871&limit=10&source=fixture');

        $I->dontSee('Authorization');
        $I->dontSee('Bearer');
        $I->dontSee('ORDER58_API_TOKEN');
        $I->dontSee('DEEPGRAM_API_KEY');
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
        // Never built into a filename, and no recording request was constructed from it. `/fetch/`
        // appears only in a URL this page actually built — the static endpoint labels elsewhere on the
        // page name `/latest-calls`, not the fetch path.
        $I->dontSee('etc/passwd.wav');
        $I->dontSee('/fetch/');
    }

    // ------------------------------------------------------------------ the Time field's validation

    /**
     * The field-level message, rendered by the server.
     *
     * The client-side validator shows the same sentence in the same element before a submit, but this
     * suite drives PhpBrowser, which runs no JavaScript — so what is pinned here is the half that must
     * work without it. The markup hooks the script needs are asserted separately below.
     */
    public function anInvalidTimeIsReportedUnderTheFieldItself(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?submitted=1&recording_id=' . self::RECORDING_ID
            . '&merchant_id=871&channel=mixed&time=2026-03-11sdasdasd&company=SWCC&name=test');

        $I->see('Time is required and must be a real date in YYYY-MM-DD format.');
        // Under the field, not only in the Result card at the bottom of the page.
        $I->seeElement('#time-error');
        $I->seeElement('input#time.field__control--error');
        $I->seeElement('input#time[aria-invalid="true"]');
    }

    /**
     * @example ["2026-03-11abc"]
     * @example ["2026/03/11"]
     * @example ["03-11-2026"]
     * @example ["2026-3-11"]
     * @example ["2026-13-01"]
     * @example ["2026-00-10"]
     * @example ["2026-02-31"]
     * @example ["2025-02-29"]
     */
    public function anInvalidTimeNeverReachesTheExternalApi(WebTester $I, \Codeception\Example $example): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?submitted=1&recording_id=' . self::RECORDING_ID
            . '&merchant_id=871&channel=mixed&time=' . urlencode((string) $example[0])
            . '&company=SWCC&name=test');

        $I->see('Nothing was sent');
        $I->see('Time is required and must be a real date in YYYY-MM-DD format.');
        // No URL was built, so none is printed. `/fetch/` appears on this page only in a request this
        // action actually constructed.
        $I->dontSee('/fetch/');
        $I->dontSee('Bytes received');
    }

    /**
     * An EMPTY `time` parameter is not the same thing as an empty Time field.
     *
     * `Action::text()` falls back to the pre-filled default whenever a query parameter is absent or
     * blank, and has always done so for every field on this page - so `?time=` arrives at validation as
     * `2026-03-11` and is accepted. That is existing behaviour and this change does not touch it.
     *
     * The empty case still matters where a user meets it: the client-side validator refuses an emptied
     * field and blocks the submit, so the blank never round-trips in the first place. The rule itself is
     * asserted in RecordingChannelTest::timeValues(), which has `''` and `'   '` as invalid.
     */
    public function anAbsentTimeParameterFallsBackToTheFormDefaultAsItAlwaysHas(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?submitted=1&recording_id=' . self::RECORDING_ID
            . '&merchant_id=871&channel=mixed&time=&company=SWCC&name=test&source=fixture');

        $I->dontSee('Time is required and must be a real date');
        $I->seeElement('input#time[value="2026-03-11"]');
    }

    /** A leap day in a leap year is a real date, and is treated as one. */
    public function aLeapDayIsAcceptedAndBehavesExactlyAsBefore(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?submitted=1&recording_id=' . self::RECORDING_ID
            . '&merchant_id=871&channel=caller&time=2024-02-29&company=SWCC&name=test&source=fixture');

        $I->dontSee('Time is required and must be a real date');
        $I->dontSeeElement('input#time.field__control--error');
        // The request still goes through the unchanged caller path.
        $I->see('22342359-caller');
    }

    /** A valid date leaves the page exactly as it was: no error anywhere near the field. */
    public function aValidTimeShowsNoFieldError(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?submitted=1&recording_id=' . self::RECORDING_ID
            . '&merchant_id=871&channel=mixed&time=2026-03-11&company=SWCC&name=test&source=fixture');

        $I->dontSee('Time is required and must be a real date');
        $I->dontSeeElement('input#time.field__control--error');
    }

    /**
     * The hooks the client-side validator attaches to.
     *
     * Asserted in the markup because the script itself cannot run here. If these disappear the page
     * still works - the server refuses a bad date either way - but the field-level feedback silently
     * stops happening, which is exactly the kind of regression nobody notices.
     */
    public function theTimeFieldCarriesTheHooksTheClientSideValidatorNeeds(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->seeElement('input#time[data-time-input]');
        // The server's own wording, handed to the script rather than restated in JavaScript.
        $I->seeElement('input#time[data-time-message="Time is required and must be a real date in YYYY-MM-DD format."]');
        $I->seeElement('input#time[aria-errormessage="time-error"]');
        $I->seeElement('#time-error');
        // Still a text input: a native date picker would render and submit in the browser's locale.
        $I->seeElement('input#time[type="text"]');
        $I->seeElement('script[src*="recording-channels"]');
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

    // ------------------------------------------------------------------ latest calls

    public function thePageOffersALatestCallsLookup(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->see('Latest calls');
        $I->seeElement('input[name="account_id"]');
        $I->seeElement('input[name="limit"]');
        $I->see('Load Latest Calls');
    }

    /**
     * Account ID and Merchant ID stay separate, and the page says why.
     *
     * The evidence that they are the same identifier is genuinely mixed, and a diagnostic tool that
     * merged them would be reporting an assumption as a fact.
     */
    public function accountIdAndMerchantIdAreSeparateFields(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->seeElement('input[name="account_id"]');
        $I->seeElement('input[name="merchant_id"]');
        $I->see('Kept separate from Merchant ID');
    }

    /** Loading the page runs no lookup, exactly as it fetches no recording. */
    public function loadingThePageDoesNotLoadCalls(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE);

        $I->dontSee('Call Session ID');
    }

    public function anInvalidAccountIdIsRefusedWithoutARequest(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?load_calls=1&account_id=abc&limit=10');

        $I->see('Nothing was sent');
        $I->see('Account ID is required');
    }

    public function anOversizedLimitIsRefusedWithoutARequest(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?load_calls=1&account_id=871&limit=99999');

        $I->see('Nothing was sent');
        $I->see('between 1 and 500');
    }

    public function theFixtureCallListRendersItsRows(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?load_calls=1&account_id=871&limit=10&source=fixture');

        $I->seeResponseCodeIs(200);
        $I->see('22342359');
        $I->see('16438291');
        $I->see('Use This Call');
    }

    /** A row whose call time this application cannot read says so, rather than inventing a date. */
    public function aRowWithAnUnreadableDateSaysTheTimeIsLeftAlone(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?load_calls=1&account_id=871&limit=10&source=fixture');

        $I->see('Date not readable');
    }

    /**
     * **The point of the whole feature**: selecting a call fills the Recording ID with that call's own
     * session id, and the three channel filenames follow it.
     */
    public function selectingACallPopulatesTheRecordingIdAndSegments(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?recording_id=16438291&merchant_id=871&channel=mixed'
            . '&time=2026-03-10&company=SWCC&name=test&account_id=871&limit=10&load_calls=1&source=fixture');

        $I->seeInField('recording_id', '16438291');
        $I->see('16438291-caller');
        $I->see('16438291-callee');
    }

    /** The date is carried only because it could be read from that row with certainty. */
    public function selectingACallCarriesADerivedDateIntoTheTimeField(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?load_calls=1&account_id=871&limit=10&source=fixture');

        // The link the page builds for the row whose callTime is `2026-03-10 09:41:12`.
        $I->seeElement('a[href*="recording_id=16438291"][href*="time=2026-03-10"]');
    }

    /** Typing an id by hand still works — the lookup is an optional convenience, not a replacement. */
    public function manualRecordingIdEntryStillWorks(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage(self::PAGE . '?submitted=1&' . $this->query() . '&source=fixture');

        $I->seeInField('recording_id', self::RECORDING_ID);
        $I->see('LOCAL FIXTURE');
        $I->see('Valid WAV');
    }

    /** Loading calls must not fetch a recording, and fetching must not clear the list. */
    public function theTwoActionsAreIndependent(WebTester $I): void
    {
        $this->signIn($I);

        $I->amOnPage(self::PAGE . '?load_calls=1&account_id=871&limit=10&source=fixture');
        $I->see('Use This Call');
        $I->dontSee('Valid WAV');

        $I->amOnPage(self::PAGE . '?submitted=1&load_calls=1&account_id=871&limit=10&'
            . $this->query() . '&source=fixture');
        $I->see('Use This Call');
        $I->see('Valid WAV');
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
