<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\WorkerAdmissionGuard;
use App\Tests\Support\AudioToTextSettingsFactory;
use App\AudioToText\Domain\SystemResourceProbeInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Admission control, over a fake probe.
 *
 * The probe is faked deliberately: a test that read this machine's real free memory would pass or fail
 * according to what else happened to be running, which is not a test of anything.
 */
final class WorkerAdmissionGuardTest extends TestCase
{
    public function testAHealthyMachineAdmitsWork(): void
    {
        $decision = $this->guard($this->probe(availableMb: 4000, load: 0.3))->decide();

        $this->assertTrue($decision->admitted);
        $this->assertNull($decision->reason);
    }

    public function testLowMemoryDefers(): void
    {
        $decision = $this->guard($this->probe(availableMb: 400, load: 0.2))->decide();

        $this->assertFalse($decision->admitted);
        $this->assertStringContainsString('available memory 400 MB', (string) $decision->reason);
    }

    public function testHighLoadDefers(): void
    {
        $decision = $this->guard($this->probe(availableMb: 8000, load: 6.0))->decide();

        $this->assertFalse($decision->admitted);
        $this->assertStringContainsString('load per core', (string) $decision->reason);
    }

    public function testAForeignWhisperProcessDefers(): void
    {
        $decision = $this->guard($this->probe(availableMb: 8000, load: 0.2, foreignWhisper: true))->decide();

        $this->assertFalse($decision->admitted);
        $this->assertStringContainsString('another whisper process', (string) $decision->reason);
    }

    /**
     * The process scan is best-effort and can be switched off; doing so must not weaken the checks that
     * are actually reliable.
     */
    public function testTheForeignProcessScanCanBeDisabled(): void
    {
        $guard = $this->guard(
            $this->probe(availableMb: 8000, load: 0.2, foreignWhisper: true),
            yieldToOtherWhisper: false,
        );

        $this->assertTrue($guard->decide()->admitted);
    }

    /**
     * Fail closed. A guard that admits an 834 MB job when it cannot measure the machine is not a guard,
     * so an unreadable `/proc` defers rather than waving the work through.
     *
     * @dataProvider brokenProbeProvider
     */
    public function testABrokenProbeDefersRatherThanAdmitting(string $failing, string $expected): void
    {
        $decision = $this->guard($this->throwingProbe($failing))->decide();

        $this->assertFalse($decision->admitted, 'A probe that cannot measure the machine must defer.');
        $this->assertStringContainsString($expected, (string) $decision->reason);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function brokenProbeProvider(): array
    {
        return [
            'memory unreadable' => ['memory', 'available memory could not be read'],
            'load unreadable' => ['load', 'system load could not be read'],
            'process scan broken' => ['process', 'foreign process check failed'],
        ];
    }

    private function guard(
        SystemResourceProbeInterface $probe,
        bool $yieldToOtherWhisper = true,
    ): WorkerAdmissionGuard {
        return new WorkerAdmissionGuard(
            AudioToTextSettingsFactory::create(yieldToOtherWhisper: $yieldToOtherWhisper),
            $probe,
        );
    }

    private function probe(int $availableMb, float $load, bool $foreignWhisper = false): SystemResourceProbeInterface
    {
        return new class ($availableMb, $load, $foreignWhisper) implements SystemResourceProbeInterface {
            public function __construct(
                private readonly int $availableMb,
                private readonly float $load,
                private readonly bool $foreignWhisper,
            ) {}

            public function availableMegabytes(): int
            {
                return $this->availableMb;
            }

            public function loadAveragePerCore(): float
            {
                return $this->load;
            }

            public function foreignWhisperRunning(): bool
            {
                return $this->foreignWhisper;
            }
        };
    }

    private function throwingProbe(string $failing): SystemResourceProbeInterface
    {
        return new class ($failing) implements SystemResourceProbeInterface {
            public function __construct(private readonly string $failing) {}

            public function availableMegabytes(): int
            {
                if ($this->failing === 'memory') {
                    throw new RuntimeException('/proc/meminfo could not be read');
                }

                return 8000;
            }

            public function loadAveragePerCore(): float
            {
                if ($this->failing === 'load') {
                    throw new RuntimeException('/proc/loadavg could not be read');
                }

                return 0.2;
            }

            public function foreignWhisperRunning(): bool
            {
                if ($this->failing === 'process') {
                    throw new RuntimeException('pgrep timed out');
                }

                return false;
            }
        };
    }
}
