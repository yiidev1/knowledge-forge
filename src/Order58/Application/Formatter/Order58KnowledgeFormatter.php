<?php

declare(strict_types=1);

namespace App\Order58\Application\Formatter;

/**
 * Renders one Order58 knowledge record into deterministic text for indexing: the store identity, a header
 * of stable identifiers and category, then the full knowledge content. `_sync_hash` is never included in
 * the body — it drives change detection, not meaning.
 */
final class Order58KnowledgeFormatter
{
    public function format(
        int $storeSourceId,
        int $sourceId,
        string $title,
        string $content,
        ?string $type,
        ?string $keyword,
        ?string $knowledgeNumber,
    ): string {
        $lines = [
            'Knowledge: ' . TextNormalizer::inline($title),
            '',
            'Store ID: ' . $storeSourceId,
            'Record ID: ' . $sourceId,
        ];

        if ($type !== null && TextNormalizer::inline($type) !== '') {
            $lines[] = 'Type: ' . TextNormalizer::inline($type);
        }
        if ($keyword !== null && TextNormalizer::inline($keyword) !== '') {
            $lines[] = 'Category: ' . TextNormalizer::inline($keyword);
        }
        if ($knowledgeNumber !== null && TextNormalizer::inline($knowledgeNumber) !== '') {
            $lines[] = 'Reference: ' . TextNormalizer::inline($knowledgeNumber);
        }

        $lines[] = '';
        $lines[] = TextNormalizer::content($content);

        return TextNormalizer::block(implode("\n", $lines));
    }
}
