<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Environment;
use App\Order58\Web\TestRecordingChannels\FixtureAvailability;
use App\Order58\Web\TestRecordingChannels\RecordingChannel;
use Codeception\Test\Unit;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function count;
use function dirname;
use function file_get_contents;
use function is_array;
use function in_array;
use function is_file;
use function strlen;
use function strpos;
use function substr;
use function substr_count;
use function token_get_all;

/**
 * The channel test tool is a debugging aid bolted onto a working application, and this keeps it one.
 *
 * It mirrors `RecordingApiTestToolIsolationTest`, which guards the original tool, and adds the two rules
 * specific to this one: the unconfirmed caller/callee mapping must stay in a single file, and the
 * fixture path must be unreachable from production.
 */
final class RecordingChannelIsolationTest extends Unit
{
    private const DIRECTORY = 'src/Order58/Web/TestRecordingChannels';

    private const PAGE_ROUTE = "Route::get('/admin/order58/test-recording-channels')";
    private const DOWNLOAD_ROUTE = "Route::get('/admin/order58/test-recording-channels/download')";

    /** The original tool, which this feature must leave exactly as it found it. */
    private const EXISTING_DIRECTORY = 'src/Order58/Web/TestRecordingApis';
    private const EXISTING_PAGE_ROUTE = "Route::get('/admin/order58/test-recording-apis')";
    private const EXISTING_DOWNLOAD_ROUTE = "Route::get('/admin/order58/test-recording-apis/download')";

    /**
     * Namespaces and APIs this tool must never reach for. Persisting anything, or handing work to the
     * queue, would turn a read-only probe into part of the ingestion path.
     *
     * `file_put_contents` and friends are absent from this list on purpose — unlike the original tool,
     * this one *reads* fixture files. Writing is still forbidden; see {@see FORBIDDEN_WRITES}.
     *
     * @var non-empty-list<string>
     */
    private const FORBIDDEN = [
        'App\\AudioToText',
        'App\\Order58\\Domain',
        'App\\Order58\\Infrastructure',
        'Yiisoft\\Db',
        'ConnectionInterface',
        'RepositoryInterface',
        'createCommand',
        'enqueue',
    ];

    /** Reading a fixture is allowed. Writing anything, anywhere, is not. */
    private const FORBIDDEN_WRITES = [
        'file_put_contents',
        'fwrite',
        'tempnam',
        'move_uploaded_file',
        'unlink',
        'mkdir',
    ];

    // ------------------------------------------------------------------ the existing tool is untouched

    /**
     * **The promise this whole feature was built around.**
     *
     * The original tool is in production use. Its source, and both of its routes, must be exactly as they
     * were — so this asserts the routes still exist, still sit inside the admin group, and that nothing
     * in this feature's directory reaches into the original's namespace.
     */
    public function testTheExistingRecordingToolsRoutesAreStillDeclared(): void
    {
        $routes = $this->read('config/common/routes.php');

        foreach ([self::EXISTING_PAGE_ROUTE, self::EXISTING_DOWNLOAD_ROUTE] as $route) {
            $this->assertStringContainsString($route, $routes, 'The existing recording tool route must not be removed.');
            $this->assertSame(1, substr_count($routes, $route), 'The existing route must be declared exactly once.');
        }
    }

    public function testTheExistingToolsSourceFilesAllStillExist(): void
    {
        foreach ([
            'Action.php',
            'BodyKind.php',
            'DownloadAction.php',
            'DownloadFilename.php',
            'ProbeResult.php',
            'RecordingApiProbe.php',
            'RecordingRequest.php',
            'template.php',
        ] as $file) {
            $this->assertTrue(
                is_file($this->path(self::EXISTING_DIRECTORY . '/' . $file)),
                $file . ' belongs to the existing tool and must not be moved or renamed.',
            );
        }
    }

    /**
     * The new tool copies the original's proven patterns rather than importing them.
     *
     * Reaching into a directory guarded by its own isolation test, from a new tool, would couple the two
     * — and the coupling would only be discovered the next time somebody edited the working one.
     */
    public function testTheNewToolDoesNotReachIntoTheExistingOne(): void
    {
        foreach ($this->sourceFiles() as $path => $source) {
            $this->assertStringNotContainsString('TestRecordingApis', $source, $path);
        }
    }

    // ------------------------------------------------------------------ this tool's own isolation

    public function testBothRoutesAreInsideTheAdminMiddlewareGroup(): void
    {
        $routes = $this->read('config/common/routes.php');

        $groupStart = strpos($routes, 'RequireAdminMiddleware::class');
        $agentGroupStart = strpos($routes, 'RequireAgentMiddleware::class');

        foreach ([self::PAGE_ROUTE, self::DOWNLOAD_ROUTE] as $route) {
            $this->assertStringContainsString($route, $routes);

            $at = strpos($routes, $route);
            $this->assertGreaterThan($groupStart, $at, $route . ' must be declared inside the admin group');
            $this->assertLessThan($agentGroupStart, $at, $route . ' must not fall into the agent group');
        }
    }

    /** URL-only, like the tool it sits beside: nothing links to it, so it is not part of the product. */
    public function testNothingLinksToTheTestTool(): void
    {
        $this->assertStringNotContainsString(
            'test-recording-channels',
            $this->read('src/Web/Shared/Layout/Admin/_sidebar.php'),
        );
        $this->assertStringNotContainsString(
            'test-recording-channels',
            $this->read('src/Web/Dashboard/template.php'),
        );
    }

    public function testTheToolTouchesNoDatabaseQueueOrAudioToTextCode(): void
    {
        $files = $this->sourceFiles();
        $this->assertGreaterThan(0, count($files), 'expected to find the tool source files');

        foreach ($files as $path => $source) {
            foreach (self::FORBIDDEN as $needle) {
                $this->assertStringNotContainsString($needle, $source, $path . ' must not reference ' . $needle);
            }
        }
    }

    /** It reads fixtures; it writes nothing, anywhere. */
    public function testTheToolNeverWritesToTheFilesystem(): void
    {
        foreach ($this->sourceFiles() as $path => $source) {
            foreach (self::FORBIDDEN_WRITES as $needle) {
                $this->assertStringNotContainsString($needle, $source, $path . ' must not write to disk');
            }
        }
    }

    /** No Authorization header is ever attached, and the IP allowlist is not worked around. */
    public function testNoCredentialIsSentUpstream(): void
    {
        foreach ($this->sourceFiles() as $path => $source) {
            $this->assertStringNotContainsString('Authorization', $source, $path);
            $this->assertStringNotContainsString('Bearer', $source, $path);
            $this->assertStringNotContainsString('X-Forwarded-For', $source, $path);
            $this->assertStringNotContainsString('ORDER58_API_TOKEN', $source, $path);
        }
    }

    // ------------------------------------------------------------------ the unconfirmed mapping

    /**
     * **The unresolved caller/callee format lives in exactly one file.**
     *
     * If a second place starts building these URLs, the two will disagree, and the disagreement presents
     * as "the caller channel returned the mixed file" — which reads as success. So the suffix strings
     * appear only in the enum that defines the convention and the one class that maps it to a request.
     */
    public function testTheChannelRequestMappingIsNotDuplicated(): void
    {
        $allowed = [
            self::DIRECTORY . '/ChannelRequestMapping.php',
            self::DIRECTORY . '/RecordingChannel.php',
        ];

        foreach ($this->sourceFiles() as $path => $source) {
            if (in_array($path, $allowed, true)) {
                continue;
            }

            $this->assertStringNotContainsString(
                '-caller.wav',
                $source,
                $path . ' must not build channel filenames — use RecordingChannel::fileNameFor()',
            );
            $this->assertStringNotContainsString(
                'api/external/recording',
                $source,
                $path . ' must not build request URLs — use ChannelRequestMapping',
            );
        }
    }

    /**
     * All three request formats are confirmed, but only mixed is available for every merchant.
     *
     * The two are separate facts and the page states them separately: a confirmed format that returns
     * 404 for an unlisted merchant is a data gap, and reading it as a mapping error sends somebody
     * looking for a bug that is not there.
     */
    public function testEveryChannelClaimsAConfirmedFormatAndOnlyMixedIsAlwaysAvailable(): void
    {
        foreach (RecordingChannel::all() as $channel) {
            $this->assertTrue(
                $channel->liveRetrievalIsConfirmed(),
                $channel->label() . ' format was confirmed by the client on 21 September 2026.',
            );
        }

        $this->assertFalse(RecordingChannel::Mixed->separatedChannelsNeedAListedMerchant());
        $this->assertTrue(RecordingChannel::Caller->separatedChannelsNeedAListedMerchant());
        $this->assertTrue(RecordingChannel::Callee->separatedChannelsNeedAListedMerchant());
    }

    // ------------------------------------------------------------------ fixtures cannot reach production

    /**
     * **Fixtures are impossible in production, and impossible silently anywhere.**
     *
     * The gate is a positive allow-list of dev and test — never `!== prod` — so an `APP_ENV` this build
     * does not recognise falls through to the live API. Failing towards the real provider is the safe
     * direction: the worst outcome is a 403 an operator can read, rather than a production page quietly
     * serving generated test tone as a customer's recording.
     */
    public function testFixturesArePermittedOnlyInDevelopmentAndTest(): void
    {
        // Asserted as an INVARIANT rather than against an expected environment. `EnvironmentTest` mutates
        // APP_ENV with putenv(), so a test that asserted "this suite runs in dev" would pass or fail
        // depending on the order Codeception happened to pick — which is a flake, not a guarantee.
        $environment = Environment::appEnv();
        $shouldBePermitted = $environment === Environment::DEV || $environment === Environment::TEST;

        $this->assertSame(
            $shouldBePermitted,
            FixtureAvailability::isPermitted(),
            'Fixtures must be permitted in exactly dev and test, and in no other environment.',
        );
    }

    /**
     * Even where permitted, nothing happens unless this request explicitly asked.
     *
     * Every negative case holds in **any** environment, so they are asserted unconditionally. The single
     * positive case depends on fixtures being permitted at all, so it is asserted against that fact
     * rather than against an assumed environment.
     */
    public function testFixturesNeverActivateWithoutAnExplicitRequest(): void
    {
        $this->assertFalse(FixtureAvailability::isRequested(null), 'absent parameter must mean live');
        $this->assertFalse(FixtureAvailability::isRequested(''), 'empty parameter must mean live');
        $this->assertFalse(FixtureAvailability::isRequested('live'));
        $this->assertFalse(FixtureAvailability::isRequested('1'), 'only the exact word opts in');
        $this->assertFalse(FixtureAvailability::isRequested(true), 'a boolean must not opt in');
        $this->assertFalse(FixtureAvailability::isRequested(['fixture']), 'an array must not opt in');
        $this->assertFalse(FixtureAvailability::isRequested('FIXTURE'), 'the opt-in is case-sensitive');

        $this->assertSame(
            FixtureAvailability::isPermitted(),
            FixtureAvailability::isRequested('fixture'),
            'The exact word opts in wherever fixtures are permitted, and nowhere else.',
        );
    }

    /** The gate is written as an allow-list, not as a negation of production. */
    public function testTheEnvironmentGateIsAnAllowListRatherThanANegation(): void
    {
        $source = $this->read(self::DIRECTORY . '/FixtureAvailability.php');
        $code = $this->stripComments($source);

        $this->assertStringNotContainsString(
            "!== Environment::PROD",
            $code,
            'A negation lets an unrecognised APP_ENV fall through to fixtures. Use a positive allow-list.',
        );
        $this->assertStringContainsString('Environment::DEV', $code);
        $this->assertStringContainsString('Environment::TEST', $code);
    }

    /** The three sample files exist, and are the ones the client named. */
    public function testTheSampleFixturesExistAndAreRealWavFiles(): void
    {
        foreach (RecordingChannel::all() as $channel) {
            $path = $this->path('tests/_data/recording-channels/' . $channel->fileNameFor('22342359'));

            $this->assertTrue(is_file($path), $path . ' is missing');

            $head = (string) file_get_contents($path, false, null, 0, 12);

            $this->assertStringStartsWith('RIFF', $head);
            $this->assertStringContainsString('WAVE', $head);
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Executable code only: comments, docblocks and inline HTML are dropped first.
     *
     * Without that, this test reads prose. These files *describe* what they refuse to do — "no
     * Authorization header is sent", "nothing is enqueued" — and a naive substring search would flag
     * those sentences while a real call hidden in a comment-free line looked identical.
     *
     * @return array<string, string> path relative to the project root => code with comments removed
     */
    private function sourceFiles(): array
    {
        $stripped = [];

        foreach ($this->rawSourceFiles() as $path => $source) {
            $stripped[$path] = $this->stripComments($source);
        }

        return $stripped;
    }

    private function stripComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $code .= $token;

                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT || $token[0] === T_INLINE_HTML) {
                continue;
            }

            $code .= $token[1];
        }

        return $code;
    }

    /**
     * @return array<string, string> path relative to the project root => file contents
     */
    private function rawSourceFiles(): array
    {
        $root = dirname(__DIR__, 3);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . self::DIRECTORY, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = (string) $file->getPathname();
            $files[substr($path, strlen($root) + 1)] = (string) file_get_contents($path);
        }

        return $files;
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->path($relative));
    }
}
