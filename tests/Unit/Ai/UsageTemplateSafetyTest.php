<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use Codeception\Test\Unit;

use function dirname;
use function file_get_contents;
use function preg_match_all;
use function str_contains;
use function substr_count;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * Static guarantees about the dashboard template.
 *
 * Asserted against the source rather than rendered output on purpose: rendering would pull the view
 * package into dev-referenced code and trip the dependency analyser, and the properties that matter
 * here — no inline script, no inline handlers, no mutating controls — are visible in the source and
 * cannot be smuggled in by data.
 *
 * The Content-Security-Policy is `script-src 'self'; style-src 'self'`, so anything inline is silently
 * dropped by the browser. A template that relied on it would look correct in review and be broken in
 * production.
 */
final class UsageTemplateSafetyTest extends Unit
{
    private string $template;

    protected function _before(): void
    {
        $this->template = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Ai/Web/Usage/template.php',
        );
    }

    public function testContainsNoInlineScriptOrStyle(): void
    {
        assertSame(0, preg_match_all('/<script/i', $this->template));
        assertSame(0, preg_match_all('/<style/i', $this->template));
        // Inline event handlers: onclick=, onsubmit=, onchange=, …
        assertSame(0, preg_match_all('/\son[a-z]+\s*=\s*["\']/i', $this->template));
        // Inline style attributes are governed by style-src-attr and would be dropped as well.
        assertSame(0, preg_match_all('/\sstyle\s*=\s*["\']/i', $this->template));
    }

    /**
     * The page is a diagnostic. Any control that could change state at the provider or in the database
     * would turn it into something that needs a confirmation flow, an audit trail and a different risk
     * assessment entirely.
     */
    public function testOffersNoMutatingAction(): void
    {
        foreach (['/delete', '/detach', '/reindex', '/re-index', '/archive', '/restore', '/remove'] as $needle) {
            assertStringNotContainsString($needle, $this->template, 'Unexpected mutating action: ' . $needle);
        }

        // Exactly one form on the page — the sync refresh — and it targets the sync route.
        assertSame(1, substr_count($this->template, '<form'));
        assertStringContainsString("generate('ai.usage.sync')", $this->template);
    }

    /**
     * The one form performs a state-changing POST, so it must carry the CSRF field.
     */
    public function testSyncFormCarriesTheCsrfField(): void
    {
        assertStringContainsString('$csrf->hiddenInput()', $this->template);
        assertStringContainsString('method="post"', $this->template);
    }

    /**
     * Every dynamic value must go through the escaper. A raw `<?= $variable ?>` of user- or
     * provider-controlled text would be an injection point.
     */
    public function testDynamicOutputIsEscaped(): void
    {
        // Count raw echoes that are not Html::encode, an integer cast, a pre-built safe fragment, or a
        // loop/structure construct.
        preg_match_all('/<\?=\s*(.+?)\s*\?>/s', $this->template, $matches);

        // Integer-typed properties are echoed bare, matching the existing house style seen in the
        // knowledge-base index, which echoes counts without the escaper. They cannot carry markup:
        // each is declared int or ?int and is hydrated through SnapshotData's typed readers, so a
        // string in the cache file becomes null or 0 rather than reaching the page.
        $allowedRaw = [
            '$csrfField',
            '$store->fileCounts->',
            '$totals->',
            '$mapping->knowledgeBaseId',
            '$mapping->localDocumentCount',
            '$mapping->localReadyDocumentCount',
            '$mapping->remoteFileCount',
        ];

        foreach ($matches[1] as $expression) {
            if (str_contains($expression, 'Html::encode')) {
                continue;
            }

            $permitted = false;
            foreach ($allowedRaw as $prefix) {
                if (str_contains($expression, $prefix)) {
                    $permitted = true;
                    break;
                }
            }

            self::assertTrue($permitted, 'Unescaped output in template: <?= ' . $expression . ' ?>');
        }
    }

    /**
     * The copy control must ship hidden and be revealed by the delegated handler, so a browser without
     * JavaScript never shows a button that cannot work. The full id stays reachable in the readonly
     * field either way.
     */
    public function testCopyButtonDegradesWithoutJavaScript(): void
    {
        assertStringContainsString('data-copy=', $this->template);
        assertStringContainsString('hidden>Copy ID</button>', $this->template);
        assertStringContainsString('readonly', $this->template);

        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/assets/main/admin.js');
        assertStringContainsString('button[data-copy]', $js);
        assertStringContainsString("removeAttribute('hidden')", $js);
    }

    /**
     * Cost figures must never be presented as billing. The page says "estimated" where it estimates and
     * "unavailable" where it cannot know.
     */
    public function testCostFiguresAreLabelledAsEstimates(): void
    {
        assertStringContainsString('Estimated', $this->template);
        assertStringContainsString('not an invoice', $this->template);
        assertStringContainsString('Unavailable — OpenAI Admin API key is not configured.', $this->template);
    }
}
