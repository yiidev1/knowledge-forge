<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Auth\Application\ThrottleParams;
use App\Auth\Infrastructure\DbLoginThrottle;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\MutableClock;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertNull;

/**
 * Exercises the throttle against a real database with a controllable clock. Skipped when no database is
 * configured.
 */
final class DbLoginThrottleTest extends Unit
{
    private const KEY = '__kf_test_throttle_key__';

    private ConnectionInterface $connection;
    private MutableClock $clock;
    private DbLoginThrottle $throttle;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->clock = new MutableClock();
        // Small numbers keep the test legible: 3 strikes in a 15-minute window, then a 15-minute lock.
        $this->throttle = new DbLoginThrottle($this->connection, $this->clock, new ThrottleParams(3, 15, 15));
        $this->removeFixture();
    }

    protected function _after(): void
    {
        $this->removeFixture();
    }

    public function testNotLockedInitially(): void
    {
        assertNull($this->throttle->retryAfterSeconds(self::KEY));
    }

    public function testLocksOnlyAfterReachingTheThreshold(): void
    {
        $this->throttle->registerFailure(self::KEY);
        $this->throttle->registerFailure(self::KEY);
        assertNull($this->throttle->retryAfterSeconds(self::KEY), 'two of three strikes must not lock');

        $this->throttle->registerFailure(self::KEY);
        assertGreaterThan(0, $this->throttle->retryAfterSeconds(self::KEY), 'the third strike must lock');
    }

    public function testLockExpiresAfterTheLockoutPeriod(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->throttle->registerFailure(self::KEY);
        }
        assertGreaterThan(0, $this->throttle->retryAfterSeconds(self::KEY));

        $this->clock->advance('+16 minutes');

        assertNull($this->throttle->retryAfterSeconds(self::KEY), 'the lock must lift once the lockout elapses');
    }

    public function testClearRemovesTheLock(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->throttle->registerFailure(self::KEY);
        }

        $this->throttle->clear(self::KEY);

        assertNull($this->throttle->retryAfterSeconds(self::KEY));
    }

    /**
     * A single failure long ago must not count toward a lock now: the window resets rather than
     * accumulating across hours, so an honest user is not punished for an old typo.
     */
    public function testStaleWindowResetsInsteadOfAccumulating(): void
    {
        $this->throttle->registerFailure(self::KEY);
        $this->throttle->registerFailure(self::KEY);

        $this->clock->advance('+20 minutes'); // beyond the 15-minute window

        $this->throttle->registerFailure(self::KEY); // this starts a fresh window
        assertNull($this->throttle->retryAfterSeconds(self::KEY), 'stale strikes must not carry over');
    }

    private function removeFixture(): void
    {
        IntegrationDb::cleanup($this->connection, '{{%auth_login_attempts}}', ['attempt_key' => self::KEY]);
    }
}
