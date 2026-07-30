<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\Infrastructure\NativePasswordHasher;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertTrue;

final class NativePasswordHasherTest extends Unit
{
    private NativePasswordHasher $hasher;

    protected function _before(): void
    {
        $this->hasher = new NativePasswordHasher();
    }

    public function testHashDoesNotEqualThePlaintext(): void
    {
        assertNotSame('correct horse battery', $this->hasher->hash('correct horse battery'));
    }

    public function testVerifyAcceptsTheCorrectPassword(): void
    {
        $hash = $this->hasher->hash('correct horse battery');

        assertTrue($this->hasher->verify('correct horse battery', $hash));
    }

    public function testVerifyRejectsTheWrongPassword(): void
    {
        $hash = $this->hasher->hash('correct horse battery');

        assertFalse($this->hasher->verify('wrong password', $hash));
    }

    public function testEachHashIsSaltedDifferently(): void
    {
        // Same password, different hashes: a stolen database cannot be attacked with a single rainbow
        // table, and two admins with the same password are not visibly identical.
        assertNotSame($this->hasher->hash('same'), $this->hasher->hash('same'));
    }

    public function testFreshHashDoesNotNeedRehashing(): void
    {
        assertFalse($this->hasher->needsRehash($this->hasher->hash('whatever')));
    }

    /**
     * A hash produced with a weaker-than-default bcrypt cost must be flagged for upgrade, which is what
     * drives the transparent rehash-on-login. This proves the mechanism works when the default moves.
     */
    public function testDetectsAHashWeakerThanTheCurrentDefault(): void
    {
        $weak = password_hash('whatever', PASSWORD_BCRYPT, ['cost' => 4]);

        assertTrue($this->hasher->needsRehash($weak));
    }
}
