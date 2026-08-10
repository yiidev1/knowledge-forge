<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\Mapper\RuleMapper;
use App\Order58\Contract\Dto\Order58RuleRecord;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;

/**
 * Rules API active resolution: no explicit field → presence = active; explicit active wins when present.
 */
final class RuleMapperActiveResolutionTest extends Unit
{
    /**
     * @dataProvider activeCases
     *
     * @param array<string, mixed> $raw
     */
    public function testResolveActive(array $raw, bool $expected): void
    {
        $record = new Order58RuleRecord(
            id: 1,
            type: 'Rule',
            title: 'T',
            description: 'D',
            ruleKeyword: null,
            createdName: null,
            sourceStoreId: null,
            createdAt: null,
            updatedAt: null,
            syncHash: 'h',
            raw: $raw,
        );

        assertSame($expected, RuleMapper::resolveActive($record));
        assertSame($expected, (new RuleMapper())->toMirror($record)->active);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: bool}>
     */
    public function activeCases(): iterable
    {
        yield 'no active field' => [['id' => 1, 'title' => 'T'], true];
        yield 'active true' => [['id' => 1, 'active' => true], true];
        yield 'active false' => [['id' => 1, 'active' => false], false];
        yield 'active 1' => [['id' => 1, 'active' => 1], true];
        yield 'active 0' => [['id' => 1, 'active' => 0], false];
        yield 'active invalid ignored' => [['id' => 1, 'active' => 'yes'], true];
    }
}
