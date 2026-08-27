<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\ForeignLockGuard;
use App\Tests\Support\AudioToTextSettingsFactory;
use PHPUnit\Framework\TestCase;

use function chmod;
use function fclose;
use function file_exists;
use function file_put_contents;
use function flock;
use function fopen;
use function implode;
use function posix_geteuid;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * Cross-project coordination, exercised against real `flock` on a temporary file.
 *
 * Real locks rather than a double, because the property being tested *is* the kernel's: two processes
 * cannot both hold an exclusive `flock`, and that is the entire reason this mechanism was chosen over a
 * process scan.
 */
final class ForeignLockGuardTest extends TestCase
{
    private string $lockPath;

    protected function setUp(): void
    {
        $this->lockPath = sys_get_temp_dir() . '/kf-foreign-lock-' . uniqid('', true) . '.lock';
        file_put_contents($this->lockPath, '');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->lockPath)) {
            @chmod($this->lockPath, 0o644);
            @unlink($this->lockPath);
        }
    }

    public function testAnUnconfiguredListIsSkipped(): void
    {
        $guard = $this->guard('');

        $this->assertNull($guard->acquire());
    }

    public function testAFreeLockIsAcquired(): void
    {
        $guard = $this->guard($this->lockPath);

        $this->assertNull($guard->acquire());

        $guard->release();
    }

    /**
     * The case the whole mechanism exists for: another project is mid-job, and this tick must not start
     * a second transcription.
     */
    public function testALockHeldElsewhereDefers(): void
    {
        $holder = fopen($this->lockPath, 'r');
        self::assertNotFalse($holder);
        flock($holder, LOCK_EX | LOCK_NB);

        $reason = $this->guard($this->lockPath)->acquire();

        $this->assertNotNull($reason);
        $this->assertStringContainsString('held by another project', $reason);

        flock($holder, LOCK_UN);
        fclose($holder);
    }

    public function testTheLockIsReleasedAndCanBeTakenAgain(): void
    {
        $first = $this->guard($this->lockPath);
        $this->assertNull($first->acquire());
        $first->release();

        $second = $this->guard($this->lockPath);
        $this->assertNull($second->acquire(), 'The lock should be free after release().');
        $second->release();
    }

    /**
     * Fail closed, and specifically for the absent case.
     *
     * "I could not determine whether the other project is mid-job" and "the other project is idle" are
     * different statements, and only one of them is safe to act on. Blanking the setting is the
     * supported way to opt out; a configured path that is not there is a misconfiguration, not consent.
     */
    public function testAConfiguredButMissingLockDefersAndIsNotCreated(): void
    {
        $missing = $this->lockPath . '-does-not-exist';

        $reason = $this->guard($missing)->acquire();

        $this->assertNotNull($reason);
        $this->assertStringContainsString('missing or unreadable', $reason);
        $this->assertFileDoesNotExist($missing, 'The guard must never create another project\'s lock file.');
    }

    /**
     * The realistic failure on this machine: the other project's lock directory is 0750 and owned by
     * another user, so a worker started as anyone else cannot read it. Deferring silently would look
     * like a dead queue, which is why the worker also reports this once at startup.
     */
    public function testAnUnreadableLockDefersAndIsReportable(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root: file permissions cannot be used to deny access.');
        }

        chmod($this->lockPath, 0o000);

        $reason = $this->guard($this->lockPath)->acquire();

        $this->assertNotNull($reason);
        $this->assertStringContainsString('missing or unreadable', $reason);

        // The same condition is reported once at worker startup, by the settings object — the single
        // validator for the module — so a stalled queue names its own cause instead of going quiet.
        $problems = AudioToTextSettingsFactory::create(foreignLocks: $this->lockPath)->problems();

        $this->assertNotSame([], $problems);
        $this->assertStringContainsString('AUDIO_WORKER_FOREIGN_LOCKS', implode(' ', $problems));
    }

    /** All or nothing: a partial acquisition must not leave another project blocked for no reason. */
    public function testAPartialAcquisitionIsFullyReleased(): void
    {
        $second = sys_get_temp_dir() . '/kf-foreign-lock-' . uniqid('', true) . '.lock';
        file_put_contents($second, '');

        $holder = fopen($second, 'r');
        self::assertNotFalse($holder);
        flock($holder, LOCK_EX | LOCK_NB);

        // The first path is free, the second is held: the guard must give the first one back.
        $this->assertNotNull($this->guard($this->lockPath . ',' . $second)->acquire());

        $prober = fopen($this->lockPath, 'r');
        self::assertNotFalse($prober);
        $this->assertTrue(
            flock($prober, LOCK_EX | LOCK_NB),
            'The first lock should have been released when the second could not be taken.',
        );

        flock($prober, LOCK_UN);
        fclose($prober);
        flock($holder, LOCK_UN);
        fclose($holder);
        @unlink($second);
    }

    private function guard(string $paths): ForeignLockGuard
    {
        return new ForeignLockGuard(AudioToTextSettingsFactory::create(foreignLocks: $paths));
    }
}
