<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Application\Operation\ReliableOperation;
use App\Ai\Contract\Dto\AiErrorDetails;
use App\Ai\Contract\Exception\AiRateLimited;
use App\Ai\Contract\Exception\AiTransportFailed;
use App\Ai\Domain\AiOperation;
use App\Ai\Domain\AiOperationStatus;
use App\Tests\Support\Fake\Ai\InMemoryAiOperationRepository;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertSame;

final class ReliableOperationTest extends Unit
{
    private const KEY = 'vs.create:kb:1';

    private InMemoryAiOperationRepository $ledger;
    private ReliableOperation $operation;

    protected function _before(): void
    {
        $this->ledger = new InMemoryAiOperationRepository();
        $this->operation = new ReliableOperation($this->ledger);
    }

    public function testSuccessRecordsResultId(): void
    {
        $resultId = $this->operation->run(self::KEY, 'vs.create', 'kb', 1, [], static fn(): string => 'vs-new');

        assertSame('vs-new', $resultId);
        $stored = $this->ledger->findByKey(self::KEY);
        assertSame(AiOperationStatus::Succeeded, $stored?->status);
        assertSame('vs-new', $stored?->resultId);
    }

    /**
     * An already-succeeded operation returns its stored id and makes no call — the core no-duplicate
     * guarantee.
     */
    public function testAlreadySucceededReusesResultWithoutCalling(): void
    {
        $this->ledger->seed($this->succeeded(self::KEY, 'vs-existing'));
        $called = false;

        $resultId = $this->operation->run(self::KEY, 'vs.create', 'kb', 1, [], static function () use (&$called): string {
            $called = true;

            return 'vs-should-not-happen';
        });

        assertSame('vs-existing', $resultId);
        assertSame(false, $called, 'the provider call must not run for a completed operation');
    }

    /**
     * A definitely-ineffective failure (rate limit) leaves the operation pending — safe to retry fresh.
     */
    public function testNonEffectiveFailureMarksPending(): void
    {
        $error = new AiRateLimited(AiErrorDetails::of('rate_limited', 'slow down', transient: true));

        try {
            $this->operation->run(self::KEY, 'vs.create', 'kb', 1, [], static fn(): string => throw $error);
            $this->fail('Expected the failure to propagate.');
        } catch (AiRateLimited) {
            assertSame(AiOperationStatus::Pending, $this->ledger->findByKey(self::KEY)?->status);
        }
    }

    /**
     * A possibly-effective failure leaves the operation needs_reconcile — never blindly retried.
     */
    public function testPossiblyEffectiveFailureMarksNeedsReconcile(): void
    {
        $error = new AiTransportFailed(AiErrorDetails::of('server_error', 'boom', transient: true, sideEffectPossible: true));

        try {
            $this->operation->run(self::KEY, 'vs.create', 'kb', 1, [], static fn(): string => throw $error);
            $this->fail('Expected the failure to propagate.');
        } catch (AiTransportFailed) {
            assertSame(AiOperationStatus::NeedsReconcile, $this->ledger->findByKey(self::KEY)?->status);
        }
    }

    public function testRetryReusesTheSameIdempotencyKey(): void
    {
        $seen = [];
        // First attempt fails ineffectively (→ pending), keeping the operation for a retry.
        try {
            $this->operation->run(self::KEY, 'vs.create', 'kb', 1, [], static function (string $key) use (&$seen): string {
                $seen[] = $key;
                throw new AiRateLimited(AiErrorDetails::of('rate_limited', 'x', transient: true));
            });
        } catch (AiRateLimited) {
            // expected
        }

        $this->operation->run(self::KEY, 'vs.create', 'kb', 1, [], static function (string $key) use (&$seen): string {
            $seen[] = $key;

            return 'vs-ok';
        });

        assertSame($seen[0], $seen[1], 'the idempotency key is stable across retries');
    }

    private function succeeded(string $key, string $resultId): AiOperation
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        return new AiOperation(1, $key, 'vs.create', 'kb', 1, AiOperationStatus::Succeeded, 'fp', 'idem', $resultId, 1, null, null, null, $now, $now, $now, $now);
    }
}
