<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use Codeception\Test\Unit;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function count;
use function dirname;
use function file_get_contents;
use function is_array;
use function strlen;
use function strpos;
use function substr;
use function token_get_all;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertLessThan;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * The recording API test tool is a debugging aid bolted onto a working application, and this test is what
 * keeps it one.
 *
 * It streams bytes from an external host to an administrator's browser, so the two properties worth
 * pinning down statically are that it stays behind the admin gate, and that it never grows a dependency
 * on the parts of the system it is explicitly forbidden to touch — the database, the queue, and
 * Audio-to-Text. Cheap source-level checks; each fails loudly on the specific way that guarantee is
 * usually lost.
 */
final class RecordingApiTestToolIsolationTest extends Unit
{
    private const DIRECTORY = 'src/Order58/Web/TestRecordingApis';

    private const PAGE_ROUTE = "Route::get('/admin/order58/test-recording-apis')";
    private const DOWNLOAD_ROUTE = "Route::get('/admin/order58/test-recording-apis/download')";

    /**
     * Namespaces and APIs this tool must never reach for. Persisting anything, or handing work to the
     * queue, would turn a read-only probe into part of the ingestion path.
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
        'file_put_contents',
        'fopen',
        'tempnam',
        'sys_get_temp_dir',
        'move_uploaded_file',
        'enqueue',
        'Queue',
        'Job',
    ];

    /** 6. The route requires an authenticated administrator, because it sits in the gated group. */
    public function testBothRoutesAreInsideTheAdminMiddlewareGroup(): void
    {
        $routes = $this->read('config/common/routes.php');

        $groupStart = strpos($routes, 'RequireAdminMiddleware::class');
        $agentGroupStart = strpos($routes, 'RequireAgentMiddleware::class');

        // Both routes must appear after the admin group opens and before the separate agent realm begins.
        foreach ([self::PAGE_ROUTE, self::DOWNLOAD_ROUTE] as $route) {
            assertStringContainsString($route, $routes);

            $at = strpos($routes, $route);
            assertGreaterThan($groupStart, $at, $route . ' must be declared inside the admin group');
            assertLessThan($agentGroupStart, $at, $route . ' must not fall into the agent group');
        }
    }

    /** The tool is URL-only: nothing links to it, so it is not part of the product surface. */
    public function testNothingLinksToTheTestTool(): void
    {
        assertStringNotContainsString(
            'test-recording-apis',
            $this->read('src/Web/Shared/Layout/Admin/_sidebar.php'),
        );
        assertStringNotContainsString(
            'test-recording-apis',
            $this->read('src/Web/Dashboard/template.php'),
        );
    }

    /**
     * 7. No database, Audio-to-Text or queue code is reachable from this directory, and nothing here
     *    writes to the filesystem.
     */
    public function testTheToolTouchesNoDatabaseQueueOrAudioToTextCode(): void
    {
        $files = $this->sourceFiles();
        assertGreaterThan(0, count($files), 'expected to find the tool source files');

        foreach ($files as $path => $source) {
            foreach (self::FORBIDDEN as $needle) {
                assertStringNotContainsString($needle, $source, $path . ' must not reference ' . $needle);
            }
        }
    }

    /** The counterpart: no Authorization header is ever attached, and the allowlist is not worked around. */
    public function testNoCredentialIsSentUpstream(): void
    {
        foreach ($this->sourceFiles() as $path => $source) {
            assertStringNotContainsString('Authorization', $source, $path);
            assertStringNotContainsString('Bearer', $source, $path);
            assertStringNotContainsString('X-Forwarded-For', $source, $path);
            assertStringNotContainsString('ORDER58_API_TOKEN', $source, $path);
        }
    }

    /**
     * Executable code only: comments, docblocks and inline HTML are dropped first.
     *
     * Without that, this test reads prose. The files here *describe* what they refuse to do — "no
     * Authorization header is sent", "nothing is enqueued" — and a naive substring search flags those
     * sentences while a real `->enqueue(...)` hidden in a docblock-free line would look identical to it.
     * Stripping to tokens means the assertions are about the code and nothing else.
     *
     * @return array<string, string> path relative to the project root => code with comments removed
     */
    private function sourceFiles(): array
    {
        $stripped = [];
        foreach ($this->rawSourceFiles() as $path => $source) {
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

            $stripped[$path] = $code;
        }

        return $stripped;
    }

    /**
     * @return array<string, string> path relative to the project root => file contents
     */
    private function rawSourceFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $directory = $root . '/' . self::DIRECTORY;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
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

    private function read(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
    }
}
