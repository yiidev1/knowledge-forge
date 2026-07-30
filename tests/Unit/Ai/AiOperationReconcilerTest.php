<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Application\Operation\AiOperationReconciler;
use App\Ai\Application\Operation\OperationTypes;
use App\Ai\Application\Operation\ReconciliationStrategyInterface;
use App\Ai\Domain\AiOperation;
use App\Ai\Domain\AiOperationStatus;
use App\Ai\OpenAi\Dto\OpenAiVectorStore;
use App\Ai\OpenAi\Reconciliation\VectorStoreCreateReconciliation;
use App\Tests\Support\Fake\Ai\FakeOpenAiClient;
use App\Tests\Support\Fake\Ai\InMemoryAiOperationRepository;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertSame;

final class AiOperationReconcilerTest extends Unit
{
    private InMemoryAiOperationRepository $ledger;

    protected function _before(): void
    {
        $this->ledger = new InMemoryAiOperationRepository();
    }

    private function seedNeedsReconcile(string $key, int $attempts = 1): void
    {
        $now = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $this->ledger->seed(new AiOperation(
            1,
            $key,
            OperationTypes::VECTOR_STORE_CREATE,
            'kb',
            12,
            AiOperationStatus::NeedsReconcile,
            'fp',
            'idem',
            null,
            $attempts,
            null,
            'server_error',
            'boom',
            $now,
            null,
            $now,
            $now,
        ));
    }

    /**
     * A strategy that finds the object adopts it: the operation becomes succeeded with the found id, and
     * no duplicate is ever created.
     */
    public function testAdoptsAnExistingObject(): void
    {
        $this->seedNeedsReconcile('vs.create:kb:12');

        $strategy = $this->strategyReturning('vs-found');
        $reconciler = new AiOperationReconciler($this->ledger, [$strategy], 5);

        assertSame(1, $reconciler->reconcileBatch(10));
        $op = $this->ledger->findByKey('vs.create:kb:12');
        assertSame(AiOperationStatus::Succeeded, $op?->status);
        assertSame('vs-found', $op?->resultId);
    }

    /**
     * A strategy that finds nothing releases the operation for a fresh attempt.
     */
    public function testReleasesWhenNothingWasCreated(): void
    {
        $this->seedNeedsReconcile('vs.create:kb:12');

        $reconciler = new AiOperationReconciler($this->ledger, [$this->strategyReturning(null)], 5);
        $reconciler->reconcileBatch(10);

        assertSame(AiOperationStatus::Pending, $this->ledger->findByKey('vs.create:kb:12')?->status);
    }

    public function testFailsWhenNoStrategyHandlesTheType(): void
    {
        $this->seedNeedsReconcile('vs.create:kb:12');

        $reconciler = new AiOperationReconciler($this->ledger, [], 5);
        $reconciler->reconcileBatch(10);

        assertSame(AiOperationStatus::Failed, $this->ledger->findByKey('vs.create:kb:12')?->status);
    }

    public function testFailsWhenAttemptsExhausted(): void
    {
        $this->seedNeedsReconcile('vs.create:kb:12', attempts: 5);

        $reconciler = new AiOperationReconciler($this->ledger, [$this->strategyReturning('vs-found')], 5);
        $reconciler->reconcileBatch(10);

        assertSame(AiOperationStatus::Failed, $this->ledger->findByKey('vs.create:kb:12')?->status);
    }

    /**
     * The real vector-store strategy adopts a store whose metadata carries the operation key.
     */
    public function testVectorStoreStrategyMatchesByMetadata(): void
    {
        $client = new FakeOpenAiClient();
        $client->vectorStores = [
            new OpenAiVectorStore('vs-other', 'x', 'completed', ['kf_op' => 'someone-else'], 0),
            new OpenAiVectorStore('vs-mine', 'x', 'completed', ['kf_op' => 'vs.create:kb:12'], 0),
        ];
        $strategy = new VectorStoreCreateReconciliation($client);

        $this->seedNeedsReconcile('vs.create:kb:12');
        (new AiOperationReconciler($this->ledger, [$strategy], 5))->reconcileBatch(10);

        assertSame('vs-mine', $this->ledger->findByKey('vs.create:kb:12')?->resultId);
    }

    private function strategyReturning(?string $id): ReconciliationStrategyInterface
    {
        return new class ($id) implements ReconciliationStrategyInterface {
            public function __construct(private ?string $id) {}

            public function supports(string $type): bool
            {
                return true;
            }

            public function resolve(AiOperation $operation): ?string
            {
                return $this->id;
            }
        };
    }
}
