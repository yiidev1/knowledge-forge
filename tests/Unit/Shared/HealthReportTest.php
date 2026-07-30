<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Health\HealthCheck;
use App\Shared\Application\Health\HealthReport;
use App\Shared\Application\Health\HealthStatus;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class HealthReportTest extends Unit
{
    public function testAllPassingIsOk(): void
    {
        $report = $this->report(HealthCheck::ok('a', 'fine'), HealthCheck::ok('b', 'fine'));

        assertSame(HealthStatus::Ok, $report->status());
        assertTrue($report->isHealthy());
    }

    /**
     * A verdict must never look better than its worst component, or a monitoring system reads "ok"
     * while the database is unreachable.
     */
    public function testOneFailureDominatesEverythingElse(): void
    {
        $report = $this->report(
            HealthCheck::ok('a', 'fine'),
            HealthCheck::warning('b', 'hmm'),
            HealthCheck::failure('c', 'broken'),
        );

        assertSame(HealthStatus::Failure, $report->status());
        assertFalse($report->isHealthy());
    }

    /**
     * A warning is deliberately not a failure: an unconfigured OpenAI key must not make the exit code
     * non-zero, or a fresh install can never pass its own health check.
     */
    public function testWarningsDoNotFailTheReport(): void
    {
        $report = $this->report(HealthCheck::ok('a', 'fine'), HealthCheck::warning('b', 'hmm'));

        assertSame(HealthStatus::Warning, $report->status());
        assertTrue($report->isHealthy());
    }

    public function testEmptyReportIsOk(): void
    {
        assertSame(HealthStatus::Ok, $this->report()->status());
    }

    public function testWithAppendsChecksAndKeepsMetadata(): void
    {
        $report = $this->report(HealthCheck::ok('a', 'fine'))->with(HealthCheck::failure('b', 'broken'));

        assertCount(2, $report->checks);
        assertSame('fingerprint-value', $report->configFingerprint);
        assertSame('test', $report->environment);
        assertSame(HealthStatus::Failure, $report->status());
    }

    public function testSerialisesForMonitoring(): void
    {
        $array = $this->report(HealthCheck::warning('a', 'hmm', 'do this'))->toArray();

        assertSame('warning', $array['status']);
        assertSame('fingerprint-value', $array['config_fingerprint']);
        assertSame('test', $array['environment']);
        assertSame(
            [['name' => 'a', 'status' => 'warning', 'message' => 'hmm', 'detail' => 'do this']],
            $array['checks'],
        );
    }

    public function testStatusRankingIsSymmetric(): void
    {
        assertSame(HealthStatus::Failure, HealthStatus::Ok->worseOf(HealthStatus::Failure));
        assertSame(HealthStatus::Failure, HealthStatus::Failure->worseOf(HealthStatus::Ok));
        assertSame(HealthStatus::Warning, HealthStatus::Warning->worseOf(HealthStatus::Ok));
        assertSame(HealthStatus::Ok, HealthStatus::Ok->worseOf(HealthStatus::Ok));
    }

    private function report(HealthCheck ...$checks): HealthReport
    {
        return new HealthReport($checks, 'fingerprint-value', 'test');
    }
}
