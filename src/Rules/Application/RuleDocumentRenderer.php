<?php

declare(strict_types=1);

namespace App\Rules\Application;

use function implode;

/**
 * Renders a canonical rule into deterministic, readable Markdown for indexing. No invented content — only the
 * rule's own title/body plus a scope header and (for store-specific rules) the matched store name.
 */
final class RuleDocumentRenderer
{
    public function render(string $title, string $body, string $scope, ?string $matchedStore): string
    {
        $lines = ['# Rule: ' . $title, '', 'Scope: ' . $scope];
        if ($matchedStore !== null && $matchedStore !== '') {
            $lines[] = 'Matched store: ' . $matchedStore;
        }
        $lines[] = '';
        $lines[] = 'Rule:';
        $lines[] = $body;

        return implode("\n", $lines) . "\n";
    }
}
