<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\ImportRunRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function dirname;
use function file_exists;
use function file_put_contents;
use function preg_replace;
use function file_get_contents;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;

/**
 * Asking the importer to run now.
 *
 * Downloading is scheduled work, and that is right — a web request must not spend minutes fetching
 * megabytes from a third party. But it meant a click could sit saying "Pending download" for a whole
 * timer interval on a server doing nothing at all: the work was correct and the product felt broken.
 *
 * This closes that gap by writing one small file, which a systemd `.path` unit watches. The run is then
 * started **by systemd**, so the unit's `CPUQuota`, `MemoryMax`, `Nice`, hardening and `--once` all
 * still apply — along with the importer's own flock and resource admission. None of them is involved
 * here, which is the point: this decides only *when* a run is attempted, never what it may do.
 *
 * Two properties are worth a test, and both are about what this does **not** do.
 */
final class ImportRunRequestTest extends TestCase
{
    /** @var list<string> */
    private array $rubbish = [];

    protected function tearDown(): void
    {
        foreach ($this->rubbish as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        foreach ($this->rubbish as $path) {
            $directory = dirname($path);

            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    /** It writes the file the unit watches, creating the directory on a fresh deployment. */
    public function testItWritesTheTriggerAndMakesItsDirectory(): void
    {
        $path = $this->path();
        self::assertFileDoesNotExist($path);

        $this->request($path)->requestRun();

        self::assertFileExists($path, 'The unit watches this file; without it nothing starts.');
        // The contents do not matter to systemd — a path unit watches for the write. A timestamp is
        // there only so somebody reading the file by hand can see when the last request was made.
        self::assertNotSame('', trim((string) file_get_contents($path)));
    }

    /** Asking twice is asking twice. Nothing accumulates and nothing is appended. */
    public function testRepeatedRequestsRewriteOneFile(): void
    {
        $path = $this->path();
        $request = $this->request($path);

        $request->requestRun();
        $request->requestRun();
        $request->requestRun();

        $contents = (string) file_get_contents($path);

        self::assertStringNotContainsString("\n1", trim($contents), 'It is appending rather than rewriting.');
    }

    /**
     * **It never fails the caller.**
     *
     * The rows are already saved by the time this is called, and the timer will take them whatever
     * happens here. A download must not fail because an optimisation could not be applied — so an
     * unwritable path is swallowed, and the only trace is a log line.
     */
    public function testAnUnwritablePathIsNotAnError(): void
    {
        // A path under a file, which cannot be made into a directory on any filesystem.
        $blocker = sys_get_temp_dir() . '/kf-trigger-blocker-' . uniqid('', true);
        file_put_contents($blocker, 'not a directory');
        $this->rubbish[] = $blocker;

        $this->request($blocker . '/nested/order58-import.trigger')->requestRun();

        // Reaching here without an exception is the whole assertion.
        self::assertTrue(true);
    }

    /**
     * It starts nothing itself.
     *
     * The rule this class exists under: a web request must never download from the provider, and must
     * never spawn a process that does. Asserted against the source, because the absence is the design —
     * a `proc_open()` here would work, need no server configuration, and silently lose every resource
     * limit the systemd unit declares, because the child inherits PHP-FPM's cgroup rather than the
     * unit's.
     */
    public function testItNeverRunsAnythingItself(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Order58/Application/ImportRunRequest.php',
        );

        // Comments stripped first: the class docblock names `proc_open()` at length, explaining why it
        // is the wrong answer here. That prose is the reason this rule exists and must not trip it.
        $code = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $source);

        foreach (['proc_open', 'shell_exec', 'passthru', 'popen', 'system(', 'exec('] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                'The web request must not start a process — systemd starts the run, with its limits.',
            );
        }
    }

    private function request(string $path): ImportRunRequest
    {
        return new ImportRunRequest($path, new NullLogger());
    }

    private function path(): string
    {
        $path = sys_get_temp_dir() . '/kf-trigger-' . uniqid('', true) . '/order58-import.trigger';
        $this->rubbish[] = $path;

        return $path;
    }
}
