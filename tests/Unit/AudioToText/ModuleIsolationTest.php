<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function implode;
use function strpos;
use function substr;
use function trim;
use function file_get_contents;
use function preg_match_all;
use function str_contains;
use function str_replace;
use function str_starts_with;

/**
 * Audio-to-Text is a bolt-on, and this test is what keeps it one.
 *
 * The feature was added to a working application, so the risk worth guarding against is not that it
 * breaks itself — the rest of the suite covers that — but that it quietly grows a dependency on
 * Order58, Chat, Rules, Stores, Agents or the existing worker, and takes them down with it later.
 *
 * These are cheap, static checks. They cannot prove the module is isolated, but each one fails loudly
 * on the specific way that isolation is usually lost.
 */
final class ModuleIsolationTest extends TestCase
{
    /**
     * Modules Audio-to-Text must never reach into.
     *
     * `Auth` is deliberately absent: the feature sits behind the application's existing administrator
     * gate and reads `CurrentAdmin`, which is the whole point of not inventing a second auth system.
     *
     * @var non-empty-list<string>
     */
    private const FORBIDDEN_MODULES = [
        'App\\Order58',
        'App\\Chat',
        'App\\Rules',
        'App\\KnowledgeBase',
        'App\\Document',
        'App\\Agent',
        'App\\Ai',
        'App\\Reports',
        'App\\Worker',
    ];

    /** Audio-to-Text must not appear inside any other module either — isolation cuts both ways. */
    public function testAudioToTextDependsOnNoOtherBusinessModule(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->root() . '/src/AudioToText') as $relative => $source) {
            foreach (self::FORBIDDEN_MODULES as $module) {
                if (str_contains($source, $module . '\\')) {
                    $offenders[] = $relative . ' depends on ' . $module;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function testNoExistingModuleDependsOnAudioToText(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->root() . '/src') as $relative => $source) {
            if (str_starts_with($relative, 'src/AudioToText/')) {
                continue;
            }

            if (str_contains($source, 'App\\AudioToText')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Existing modules must not reference Audio-to-Text:\n" . implode("\n", $offenders),
        );
    }

    /**
     * The shared worker keeps its own drainer list.
     *
     * Transcription holds one CPU core for ninety seconds; running it inside `kf:worker:run` would stall
     * document processing and Order58 sync behind every recording. Separate command, separate lock,
     * separate schedule — and this asserts it stayed that way.
     */
    public function testTheSharedWorkerDoesNotRunTranscription(): void
    {
        $worker = (string) file_get_contents($this->root() . '/config/common/di/worker.php');

        $this->assertStringNotContainsString('AudioToText', $worker);

        $runner = (string) file_get_contents($this->root() . '/src/Worker/Application/WorkerRunner.php');

        $this->assertStringNotContainsString('AudioToText', $runner);
    }

    /**
     * Every Audio-to-Text CSS rule is scoped to the feature's own `a2t-` prefix.
     *
     * The stylesheet is shared with the whole admin panel, so an unprefixed selector appended at the end
     * would silently restyle pages this feature has nothing to do with.
     */
    public function testAudioToTextCssIsScopedToItsOwnPrefix(): void
    {
        $css = (string) file_get_contents($this->root() . '/assets/main/admin.css');
        $marker = 'Audio to Text';
        $block = substr($css, (int) strpos($css, $marker));

        preg_match_all('/^([.#][a-zA-Z][^{]*)\{/m', $block, $matches);

        $unscoped = [];
        foreach ($matches[1] as $selector) {
            $selector = trim($selector);

            // `.content:has(.a2t-wide)` widens one page, and does so only when that page is on screen —
            // scoped by the `:has()` condition rather than by the leading class.
            if (str_contains($selector, 'a2t-')) {
                continue;
            }

            $unscoped[] = $selector;
        }

        $this->assertSame(
            [],
            $unscoped,
            "Audio-to-Text CSS must stay under the .a2t- prefix:\n" . implode("\n", $unscoped),
        );
    }

    /**
     * The polling code activates on Audio-to-Text data attributes and nothing else, so a page without
     * them — every other page in the application — never starts a timer or a fetch loop.
     */
    public function testAudioToTextJavaScriptOnlyActivatesOnItsOwnAttributes(): void
    {
        $js = (string) file_get_contents($this->root() . '/assets/main/admin.js');
        $block = substr($js, (int) strpos($js, 'Audio to Text — job status polling'));

        $this->assertStringContainsString("querySelector('[data-a2t-poll]')", $block);
        $this->assertStringContainsString("querySelector('[data-a2t-reload]')", $block);

        // Every DOM query in the block must be attribute- or class-scoped to the feature.
        preg_match_all('/querySelector(?:All)?\(([^)]*)\)/', $block, $matches);

        foreach ($matches[1] as $query) {
            $this->assertStringContainsString('a2t', $query, 'Unscoped DOM query: ' . $query);
        }
    }

    /** No Audio-to-Text migration may touch a table it did not create. */
    public function testAudioToTextMigrationsOnlyTouchTheirOwnTables(): void
    {
        $migrations = [
            'M260826120000CreateAudioTranscriptionJobs',
            'M260826120100AddSpeakerSeparationColumns',
            'M260826130000RetainSuccessfulRecordings',
        ];

        foreach ($migrations as $class) {
            $source = (string) file_get_contents($this->root() . '/src/Migration/' . $class . '.php');

            preg_match_all('/(?:ALTER|CREATE|DROP)\s+TABLE(?:\s+IF\s+EXISTS)?\s+`([a-z_]+)`/i', $source, $matches);

            foreach ($matches[1] as $table) {
                $this->assertContains(
                    $table,
                    ['audio_transcription_jobs', 'audio_worker_heartbeat'],
                    $class . ' must not modify the existing table "' . $table . '".',
                );
            }
        }
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return iterable<string, string> relative path => source
     */
    private function phpFilesIn(string $directory): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            yield str_replace($this->root() . '/', '', $file->getPathname())
                => (string) file_get_contents($file->getPathname());
        }
    }
}
