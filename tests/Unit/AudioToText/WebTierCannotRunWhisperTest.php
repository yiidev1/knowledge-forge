<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function is_dir;
use function str_contains;
use function str_replace;
use function token_get_all;

use const T_COMMENT;
use const T_DOC_COMMENT;

/**
 * An architectural boundary, checked rather than trusted.
 *
 * The rule: **nothing under a `Web/` directory may reach the transcription toolchain.** Transcription
 * costs ninety-four seconds of a CPU core and 834 MB on this hardware, measured; inside PHP-FPM that is
 * a worker held for a minute and a half and a request the browser abandons. The upload action validates
 * and queues, and the console worker does the work.
 *
 * It is checked because it is trivially easy to break by accident — one constructor argument — and
 * essentially invisible in review once the surrounding code has grown. A test states the rule where
 * someone will actually meet it.
 */
final class WebTierCannotRunWhisperTest extends TestCase
{
    /**
     * Naming any of these from the web tier means the boundary has been crossed.
     *
     * @var non-empty-list<string>
     */
    private const FORBIDDEN_IN_WEB_TIER = [
        'AudioTranscriber',
        'SpeakerDiarizer',
        'SpeakerSeparationService',
        'ProcessRunner',
        'proc_open',
        'shell_exec',
        'passthru',
        'whisper-cli',
        'popen',
        'system(',
    ];

    public function testNoWebActionCanReachTheTranscriptionToolchain(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];

        foreach ($this->webPhpFiles($root) as $relativePath => $source) {
            foreach (self::FORBIDDEN_IN_WEB_TIER as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = $relativePath . ' references ' . $needle;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "The web tier must not reach the transcription toolchain:\n  " . implode("\n  ", $offenders),
        );
    }

    /**
     * The upload action enqueues and does nothing else. Stated separately from the sweep above so a
     * failure names the actual rule rather than a generic boundary violation.
     *
     * The upload form lives on a store's own page now — every conversion belongs to a store, and the
     * store comes from the URL — so this is the file that has to hold the line. `/audio-to-text` is a
     * redirect to the picker and enqueues nothing at all.
     */
    public function testTheUploadActionOnlyEnqueues(): void
    {
        $source = $this->codeWithoutComments(
            (string) file_get_contents(dirname(__DIR__, 3) . '/src/AudioToText/Web/Job/Store/Action.php'),
        );

        $this->assertStringContainsString('TranscriptionQueue', $source);
        $this->assertStringNotContainsString('transcribeFile', $source);
    }

    /**
     * The transcriber offers no upload-shaped entry point, so there is nothing for an action to be
     * tempted to call. Absence of the door, rather than a sign on it.
     */
    public function testTheTranscriberTakesAPathNotAnUpload(): void
    {
        $source = $this->codeWithoutComments(
            (string) file_get_contents(dirname(__DIR__, 3) . '/src/AudioToText/Infrastructure/AudioTranscriber.php'),
        );

        $this->assertStringContainsString('public function transcribeFile(', $source);
        $this->assertStringContainsString('string $sourcePath', $source);
        $this->assertStringNotContainsString('UploadedFileInterface', $source);
    }

    /**
     * Every `Web/` directory in the application, with comments stripped.
     *
     * Stripping matters and is not incidental: a doc block explaining *why* a class must not reach the
     * transcriber necessarily names the transcriber. Without this, documenting the rule would break it.
     *
     * @return iterable<string, string> relative path => comment-free source
     */
    private function webPhpFiles(string $root): iterable
    {
        $directory = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($directory as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if (!str_contains($path, '/Web/')) {
                continue;
            }

            $relative = str_replace($root . '/', '', $path);

            if (in_array($relative, $this->allowed(), true)) {
                continue;
            }

            yield $relative => $this->codeWithoutComments((string) file_get_contents($path));
        }
    }

    /**
     * Deliberately empty.
     *
     * telecom-billing's equivalent needed an allow-list because its feature lived entirely under
     * `src/Web/AudioToText/`, so the transcriber sat inside the very tree being swept. Here the module
     * is `src/AudioToText/` with a nested `Web/`, and every process-running class lives under
     * `Infrastructure/` — outside the sweep by construction. An empty allow-list is a stronger
     * guarantee than a populated one, so it stays empty until something genuinely warrants an entry.
     *
     * @return list<string>
     */
    private function allowed(): array
    {
        return [];
    }

    private function codeWithoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    protected function setUp(): void
    {
        if (!is_dir(dirname(__DIR__, 3) . '/src')) {
            $this->markTestSkipped('Source tree not found.');
        }
    }
}
