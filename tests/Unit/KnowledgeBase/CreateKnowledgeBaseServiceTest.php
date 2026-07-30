<?php

declare(strict_types=1);

namespace App\Tests\Unit\KnowledgeBase;

use App\KnowledgeBase\Application\CreateKnowledgeBaseService;
use App\KnowledgeBase\Application\SlugGenerator;
use App\KnowledgeBase\Domain\VectorStoreStatus;
use App\Shared\Domain\Exception\ValidationException;
use App\Tests\Support\Fake\ImmediateTransactionRunner;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseRepository;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final class CreateKnowledgeBaseServiceTest extends Unit
{
    private InMemoryKnowledgeBaseRepository $repository;
    private CreateKnowledgeBaseService $service;

    protected function _before(): void
    {
        $this->repository = new InMemoryKnowledgeBaseRepository();
        $this->service = new CreateKnowledgeBaseService(
            $this->repository,
            new SlugGenerator($this->repository),
            new ImmediateTransactionRunner(),
        );
    }

    public function testCreatesWithGeneratedSlugAndPendingProvisioning(): void
    {
        $id = $this->service->create('HR Policies', 'Company docs', 'Be concise');

        $kb = $this->repository->findById($id);
        assertSame('HR Policies', $kb?->name());
        assertSame('hr-policies', $kb?->slug());
        assertSame('Company docs', $kb?->description());
        // A new base is never immediately ready — provisioning is a background job.
        assertSame(VectorStoreStatus::Pending, $kb?->vectorStoreStatus());
    }

    public function testTrimsAndNullsBlankOptionalFields(): void
    {
        $id = $this->service->create('  Spaced  ', '   ', '');

        $kb = $this->repository->findById($id);
        assertSame('Spaced', $kb?->name());
        assertNull($kb?->description());
        assertNull($kb?->systemInstructions());
    }

    public function testGeneratesDistinctSlugsForDuplicateNames(): void
    {
        $first = $this->repository->findById($this->service->create('Report', null, null));
        $second = $this->repository->findById($this->service->create('Report', null, null));

        assertSame('report', $first?->slug());
        assertSame('report-2', $second?->slug());
    }

    public function testRejectsAnEmptyName(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create('   ', null, null);
    }

    public function testRejectsAnOverlongName(): void
    {
        try {
            $this->service->create(str_repeat('x', 161), null, null);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            assertSame(['name'], array_keys($e->errors()));
        }
    }
}
