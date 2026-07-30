<?php

declare(strict_types=1);

namespace App\Tests\Integration\KnowledgeBase;

use App\KnowledgeBase\Domain\KnowledgeBaseStatus;
use App\KnowledgeBase\Domain\VectorStoreStatus;
use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Exercises the knowledge-base repository against a real MySQL database. Skipped when none is
 * configured. Fixtures use a distinctive slug prefix and are removed per test.
 */
final class DbKnowledgeBaseRepositoryTest extends Unit
{
    private const SLUG = '__kf_test_kb__';

    private ConnectionInterface $connection;
    private DbKnowledgeBaseRepository $repository;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->repository = new DbKnowledgeBaseRepository($this->connection, new SystemClock());
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testCreateStartsPendingAndActive(): void
    {
        $id = $this->repository->create('Test KB', self::SLUG, 'desc', 'instructions');

        $kb = $this->repository->findById($id);
        assertNotNull($kb);
        assertSame('Test KB', $kb->name());
        assertSame(self::SLUG, $kb->slug());
        assertSame('desc', $kb->description());
        assertSame('instructions', $kb->systemInstructions());
        // The vector store is provisioned later by the worker.
        assertSame(VectorStoreStatus::Pending, $kb->vectorStoreStatus());
        assertSame(KnowledgeBaseStatus::Active, $kb->status());
        assertNull($kb->openaiVectorStoreId());
    }

    public function testFindBySlugAndSlugExists(): void
    {
        $this->repository->create('Test KB', self::SLUG, null, null);

        assertNotNull($this->repository->findBySlug(self::SLUG));
        assertTrue($this->repository->slugExists(self::SLUG));
        assertNull($this->repository->findBySlug('__kf_missing__'));
        assertFalse($this->repository->slugExists('__kf_missing__'));
    }

    public function testUpdateDetailsKeepsSlug(): void
    {
        $id = $this->repository->create('Original', self::SLUG, null, null);

        $this->repository->updateDetails($id, 'Renamed', 'new desc', null);

        $kb = $this->repository->findById($id);
        assertSame('Renamed', $kb?->name());
        assertSame('new desc', $kb?->description());
        // The slug must survive a rename so existing links keep working.
        assertSame(self::SLUG, $kb?->slug());
    }

    public function testArchiveHidesFromDefaultListing(): void
    {
        $id = $this->repository->create('Test KB', self::SLUG, null, null);

        $this->repository->updateStatus($id, KnowledgeBaseStatus::Archived);

        $active = array_filter($this->repository->findAll(false), fn($kb) => $kb->slug() === self::SLUG);
        assertCount(0, $active, 'archived base is absent from the default listing');

        $all = array_filter($this->repository->findAll(true), fn($kb) => $kb->slug() === self::SLUG);
        assertCount(1, $all, 'archived base is present when explicitly included');
    }

    private function cleanup(): void
    {
        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['slug' => self::SLUG]);
    }
}
