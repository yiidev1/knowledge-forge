<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_intersect;
use function array_unique;
use function array_values;
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

use const PREG_SET_ORDER;

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

    /**
     * No migration that touches this module's tables may touch anybody else's in the same breath.
     *
     * ## Why this is discovered rather than listed
     *
     * It used to name six migration classes explicitly, and by the time anyone looked there were two it
     * had never heard of — a list of files to remember to add to is a list that is wrong, and silently:
     * the test went on passing while checking less and less of what it claimed to.
     *
     * So the rule is inverted. Every migration in the tree is read, and a migration is this module's
     * business if it touches **any** `audio_*` table. Those must touch nothing else. That needs no
     * maintenance, and it is strictly stronger in both directions: it also catches another feature's
     * migration reaching into `audio_conversations`, which the old list could not see at all.
     */
    public function testAudioToTextMigrationsOnlyTouchTheirOwnTables(): void
    {
        $allowed = [
            'audio_transcription_jobs',
            'audio_worker_heartbeat',
            'audio_to_text_settings',
            'audio_conversations',
            'audio_tts_renditions',
            // The review layer's own table. It was missing from the list this replaced — and its
            // migration was one of the six that list named, which is how little the old check saw.
            'audio_segment_revisions',
        ];

        $checked = 0;

        foreach ($this->phpFilesIn($this->root() . '/src/Migration') as $relative => $source) {
            $tables = $this->tablesTouchedBy($source);

            // Not ours: a migration that never names an audio table is another feature's, and this test
            // has nothing to say about it.
            if (array_intersect($tables, $allowed) === []) {
                continue;
            }

            ++$checked;

            foreach ($tables as $table) {
                $this->assertContains(
                    $table,
                    $allowed,
                    $relative . ' touches this module\'s tables and also "' . $table . '", which is '
                        . 'another feature\'s. Split it, or the two features can no longer be migrated '
                        . 'independently.',
                );
            }
        }

        // A regex that stopped matching would otherwise turn this into a test that checks nothing and
        // passes faster. Six were listed by hand when this was written; there must be at least that many.
        $this->assertGreaterThanOrEqual(
            6,
            $checked,
            'No migrations were recognised as this module\'s, so this proves nothing.',
        );
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return iterable<string, string> relative path => source
     */
    /**
     * Every table one migration's SQL names.
     *
     * Almost none of them are written as literals: the normal form in this tree is
     * ``'ALTER TABLE `' . self::JOBS . '`'``, so a regex looking only for backticked words finds nothing
     * in 61 of the 80-odd statements here. That is not a hypothetical — it is why the hand-written list
     * this replaced was passing while checking almost nothing, including the very migration it named
     * first. So the file's own constants are resolved before the SQL is read.
     *
     * @return list<string>
     */
    private function tablesTouchedBy(string $source): array
    {
        // `private const JOBS = 'audio_transcription_jobs';` — the only shape used, and a value that is
        // not a bare table name simply never matches a table reference below.
        preg_match_all('/const\s+([A-Z_][A-Z0-9_]*)\s*=\s*\'([a-z0-9_]+)\'/', $source, $constants, PREG_SET_ORDER);

        $names = [];

        foreach ($constants as [, $name, $value]) {
            $names[$name] = $value;
        }

        preg_match_all(
            '/(?:ALTER|CREATE|DROP)\s+TABLE(?:\s+IF\s+EXISTS)?\s+`(?:([a-z0-9_]+)`|\'\s*\.\s*self::([A-Z_][A-Z0-9_]*))/i',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $tables = [];

        foreach ($matches as $match) {
            // Group 1 is a literal name, group 2 a constant to look up. Exactly one is ever set.
            $literal = $match[1] ?? '';

            if ($literal !== '') {
                $tables[] = $literal;

                continue;
            }

            $constant = $match[2] ?? '';

            if ($constant !== '' && isset($names[$constant])) {
                $tables[] = $names[$constant];
            }
        }

        return array_values(array_unique($tables));
    }

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
