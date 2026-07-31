<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\Formatter\Order58KnowledgeFormatter;
use App\Order58\Application\Formatter\Order58StoreProfileFormatter;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * The generated-document formatters must be deterministic — byte-identical for the same input — so an
 * unchanged source record never produces a "changed" checksum. `_sync_hash` must never leak into the body.
 */
final class Order58FormatterTest extends Unit
{
    private function storeSnapshot(): array
    {
        return [
            'id' => 1861,
            'name' => 'Bamboo House',
            'active' => true,
            'fields' => [
                'Company' => 'WAOW',
                'City' => 'Groton',
                'State' => 'CT',
                'Phone' => '(860) 449-0088',
                'Hours' => "10AM - 3:30PM\r\n(Mon - Sun)",
            ],
        ];
    }

    public function testStoreProfileIsByteIdentical(): void
    {
        $formatter = new Order58StoreProfileFormatter();

        assertSame($formatter->format($this->storeSnapshot()), $formatter->format($this->storeSnapshot()));
    }

    public function testStoreProfileContainsKeyFacts(): void
    {
        $text = (new Order58StoreProfileFormatter())->format($this->storeSnapshot());

        assertStringContainsString('Store Profile: Bamboo House', $text);
        assertStringContainsString('Status: Active', $text);
        assertStringContainsString('Store ID: 1861', $text);
        assertStringContainsString('City: Groton', $text);
        // Multi-line source values are collapsed deterministically to a single labelled line.
        assertStringContainsString('Hours: 10AM - 3:30PM (Mon - Sun)', $text);
    }

    public function testKnowledgeIsDeterministicAndChangesWithContent(): void
    {
        $formatter = new Order58KnowledgeFormatter();

        $a = $formatter->format(61, 44, 'Dish', 'General Tso', 'Knowledge', 'order', '100612001');
        $b = $formatter->format(61, 44, 'Dish', 'General Tso', 'Knowledge', 'order', '100612001');
        $changed = $formatter->format(61, 44, 'Dish', 'Sesame Chicken', 'Knowledge', 'order', '100612001');

        assertSame($a, $b);
        assertNotSame($a, $changed);
        assertStringContainsString('Store ID: 61', $a);
        assertStringContainsString('Record ID: 44', $a);
        assertStringContainsString('General Tso', $a);
    }

    public function testNormalizationUnifiesLineEndings(): void
    {
        $formatter = new Order58KnowledgeFormatter();

        $crlf = $formatter->format(1, 2, 'T', "line1\r\nline2", null, null, null);
        $lf = $formatter->format(1, 2, 'T', "line1\nline2", null, null, null);

        assertSame($lf, $crlf);
        assertStringNotContainsString("\r", $crlf);
    }
}
