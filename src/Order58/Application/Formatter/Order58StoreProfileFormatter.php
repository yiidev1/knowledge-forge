<?php

declare(strict_types=1);

namespace App\Order58\Application\Formatter;

use function is_array;
use function is_int;
use function is_string;

/**
 * Renders a store's curated snapshot into deterministic, agent-useful text for indexing. Formats from the
 * stored snapshot (not the raw API record) so a live sync and a later "rebuild" produce byte-identical
 * text. `_sync_hash` never appears in the body.
 */
final class Order58StoreProfileFormatter
{
    /**
     * @param array<array-key, mixed> $snapshot As built by {@see \App\Order58\Application\Mapper\StoreMapper}:
     *                                          `id`, `name`, `active`, and an ordered `fields` map.
     */
    public function format(array $snapshot): string
    {
        $idRaw = $snapshot['id'] ?? null;
        $nameRaw = $snapshot['name'] ?? null;
        $fieldsRaw = $snapshot['fields'] ?? null;

        $id = is_int($idRaw) ? $idRaw : 0;
        $name = is_string($nameRaw) ? $nameRaw : '';
        $active = ($snapshot['active'] ?? false) === true;
        $fields = is_array($fieldsRaw) ? $fieldsRaw : [];

        $lines = [
            'Store Profile: ' . TextNormalizer::inline($name),
            '',
            'Store ID: ' . $id,
            'Status: ' . ($active ? 'Active' : 'Inactive'),
        ];

        foreach ($fields as $label => $value) {
            if (!is_string($label)) {
                continue;
            }
            $text = TextNormalizer::inline(is_string($value) ? $value : (string) (is_int($value) ? $value : ''));
            if ($text !== '') {
                $lines[] = $label . ': ' . $text;
            }
        }

        return TextNormalizer::block(implode("\n", $lines));
    }
}
