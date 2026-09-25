<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Machine\MachineResourceProbeInterface;
use App\Shared\Machine\ResourceAdmission;
use App\Shared\Machine\ResourceBudget;
use Codeception\Test\Unit;
use RuntimeException;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * The generic machine-resource gate, over a fake probe.
 *
 * The probe is faked deliberately: a test that read this machine's real free memory would pass or fail
 * according to whatever else happened to be running, which is no test at all.
 *
 * The point these tests exist to protect is the **budget**. Two workers on this server ask the same two
 * questions and need different answers — one guards a 904 MB transcription, the other a 92 MB download —
 * and a single global threshold would get one of them wrong.
 */
final class ResourceAdmissionTest extends Unit
{
    public function testAHealthyMachineAdmitsWork(): void
    {
        $decision = $this->decide(availableMb: 4000, load: 0.3, budget: new ResourceBudget(350, 1.5));

        assertTrue($decision->admitted);
        assertNull($decision->reason);
    }

    public function testMemoryBelowTheBudgetDefers(): void
    {
        $decision = $this->decide(availableMb: 200, load: 0.2, budget: new ResourceBudget(350, 1.5));

        assertFalse($decision->admitted);
        assertStringContainsString('available memory 200 MB is below the 350 MB required', (string) $decision->reason);
    }

    public function testLoadAboveTheBudgetDefers(): void
    {
        $decision = $this->decide(availableMb: 4000, load: 2.4, budget: new ResourceBudget(350, 1.5));

        assertFalse($decision->admitted);
        assertStringContainsString('load per core 2.40 exceeds the 1.50 threshold', (string) $decision->reason);
    }

    /**
     * The whole reason the budget is a parameter rather than a setting: one machine reading, two verdicts.
     *
     * 500 MB available is plenty for a recording download and nowhere near enough for a transcription, and
     * both statements must be true at the same instant.
     */
    public function testTheSameMachineAdmitsALightBudgetAndDefersAHeavyOne(): void
    {
        $probe = $this->probe(availableMb: 500, load: 0.4);
        $admission = new ResourceAdmission($probe);

        assertTrue(
            $admission->decide(new ResourceBudget(350, 1.5))->admitted,
            'A 92 MB download must not be blocked by a threshold sized for whisper.',
        );
        assertFalse(
            $admission->decide(new ResourceBudget(1500, 1.5))->admitted,
            'A 904 MB transcription must still wait.',
        );
    }

    /** A threshold of 0 opts out of the memory check without opting out of the load check. */
    public function testAZeroMemoryBudgetAdmitsAnyAmountOfMemory(): void
    {
        assertTrue($this->decide(availableMb: 0, load: 0.1, budget: new ResourceBudget(0, 1.5))->admitted);
    }

    /** The boundary is "below", not "at or below": exactly the budget is enough. */
    public function testExactlyTheBudgetIsAdmitted(): void
    {
        assertTrue($this->decide(availableMb: 350, load: 1.5, budget: new ResourceBudget(350, 1.5))->admitted);
    }

    /**
     * Fail closed. A gate that admits work when it cannot measure the machine is not a gate, so an
     * unreadable `/proc` defers rather than waving the work through.
     *
     * @dataProvider brokenProbeProvider
     */
    public function testABrokenProbeDefersRatherThanAdmitting(string $failing, string $expected): void
    {
        $decision = (new ResourceAdmission($this->throwingProbe($failing)))
            ->decide(new ResourceBudget(350, 1.5));

        assertFalse($decision->admitted, 'A probe that cannot measure the machine must defer.');
        assertStringContainsString($expected, (string) $decision->reason);
    }

    /** @return array<string, array{string, string}> */
    public static function brokenProbeProvider(): array
    {
        return [
            'memory unreadable' => ['memory', 'available memory could not be read'],
            'load unreadable' => ['load', 'system load could not be read'],
        ];
    }

    /**
     * Memory is checked first, and on a server with no swap that order is the point: memory is what kills
     * a process, load only makes it slow. A machine failing both should say so about the memory.
     */
    public function testMemoryIsReportedBeforeLoad(): void
    {
        $decision = $this->decide(availableMb: 100, load: 9.9, budget: new ResourceBudget(350, 1.5));

        assertStringContainsString('available memory', (string) $decision->reason);
    }

    /** The load probe is never even called once memory has already failed. */
    public function testLoadIsNotProbedWhenMemoryAlreadyFailed(): void
    {
        $probe = new class implements MachineResourceProbeInterface {
            public int $loadCalls = 0;

            public function availableMegabytes(): int
            {
                return 10;
            }

            public function loadAveragePerCore(): float
            {
                $this->loadCalls++;

                return 0.1;
            }
        };

        (new ResourceAdmission($probe))->decide(new ResourceBudget(350, 1.5));

        assertSame(0, $probe->loadCalls);
    }

    private function decide(int $availableMb, float $load, ResourceBudget $budget): \App\Shared\Machine\AdmissionDecision
    {
        return (new ResourceAdmission($this->probe($availableMb, $load)))->decide($budget);
    }

    private function probe(int $availableMb, float $load): MachineResourceProbeInterface
    {
        return new class ($availableMb, $load) implements MachineResourceProbeInterface {
            public function __construct(
                private readonly int $availableMb,
                private readonly float $load,
            ) {}

            public function availableMegabytes(): int
            {
                return $this->availableMb;
            }

            public function loadAveragePerCore(): float
            {
                return $this->load;
            }
        };
    }

    private function throwingProbe(string $failing): MachineResourceProbeInterface
    {
        return new class ($failing) implements MachineResourceProbeInterface {
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
        };
    }
}
