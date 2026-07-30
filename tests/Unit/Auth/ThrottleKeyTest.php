<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\Application\ThrottleKey;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;

final class ThrottleKeyTest extends Unit
{
    public function testIsAStableSha256Hex(): void
    {
        $key = ThrottleKey::for('admin', '203.0.113.5');

        assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key);
        assertSame($key, ThrottleKey::for('admin', '203.0.113.5'));
    }

    public function testUsernameIsCaseInsensitive(): void
    {
        assertSame(ThrottleKey::for('Admin', '203.0.113.5'), ThrottleKey::for('admin', '203.0.113.5'));
    }

    public function testDifferentUserOrIpProducesADifferentBucket(): void
    {
        $base = ThrottleKey::for('admin', '203.0.113.5');

        assertNotSame($base, ThrottleKey::for('admin', '203.0.113.6'));
        assertNotSame($base, ThrottleKey::for('root', '203.0.113.5'));
    }

    public function testDoesNotContainThePlaintextIdentifiers(): void
    {
        $key = ThrottleKey::for('secretadmin', '203.0.113.5');

        assertStringNotContainsString('secretadmin', $key);
        assertStringNotContainsString('203.0.113.5', $key);
    }
}
