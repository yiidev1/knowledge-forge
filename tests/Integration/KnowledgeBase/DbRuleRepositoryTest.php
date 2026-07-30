<?php

declare(strict_types=1);

namespace App\Tests\Integration\KnowledgeBase;

use App\KnowledgeBase\Infrastructure\DbKnowledgeBaseRepository;
use App\KnowledgeBase\Infrastructure\DbRuleRepository;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use Yiisoft\Db\Connection\ConnectionInterface;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * Exercises the rule repository against a real database, including the grouped-count query and the
 * enabled-only ordering the prompt builder relies on. Skipped when no database is configured.
 */
final class DbRuleRepositoryTest extends Unit
{
    private const SLUG = '__kf_test_rules_kb__';

    private ConnectionInterface $connection;
    private DbRuleRepository $rules;
    private int $kbId;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        $kbRepo = new DbKnowledgeBaseRepository($this->connection, new SystemClock());
        $this->kbId = $kbRepo->create('Rules KB', self::SLUG, null, null);
        $this->rules = new DbRuleRepository($this->connection, new SystemClock());
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testCreateAndOrderByPriority(): void
    {
        $this->rules->create($this->kbId, 'B', 'b', 20, true);
        $this->rules->create($this->kbId, 'A', 'a', 10, true);

        $names = array_map(static fn($r) => $r->name(), $this->rules->findAllForKnowledgeBase($this->kbId));
        assertSame(['A', 'B'], $names, 'rules come back in priority order');
    }

    public function testEnabledOnlyExcludesDisabled(): void
    {
        $this->rules->create($this->kbId, 'On', 'x', 10, true);
        $this->rules->create($this->kbId, 'Off', 'x', 20, false);

        $names = array_map(static fn($r) => $r->name(), $this->rules->findEnabledForKnowledgeBase($this->kbId));
        assertSame(['On'], $names);
    }

    public function testIsEnabledRoundTripsAsBoolean(): void
    {
        $id = $this->rules->create($this->kbId, 'Flag', 'x', 10, true);
        assertTrue($this->rules->findByIdForKnowledgeBase($id, $this->kbId)?->isEnabled());

        $this->rules->setEnabled($id, false);
        assertFalse($this->rules->findByIdForKnowledgeBase($id, $this->kbId)?->isEnabled());
    }

    public function testScopedLookupRejectsForeignKnowledgeBase(): void
    {
        $id = $this->rules->create($this->kbId, 'Mine', 'x', 10, true);

        // A different knowledge-base id must not resolve this rule.
        assertSame(null, $this->rules->findByIdForKnowledgeBase($id, $this->kbId + 99999));
    }

    public function testNameExistsRespectsExclusion(): void
    {
        $id = $this->rules->create($this->kbId, 'Unique', 'x', 10, true);

        assertTrue($this->rules->nameExistsInKnowledgeBase('Unique', $this->kbId));
        // Excluding the rule itself lets it keep its own name on update.
        assertFalse($this->rules->nameExistsInKnowledgeBase('Unique', $this->kbId, $id));
    }

    public function testMaxPriority(): void
    {
        assertSame(null, $this->rules->maxPriority($this->kbId));

        $this->rules->create($this->kbId, 'A', 'x', 10, true);
        $this->rules->create($this->kbId, 'B', 'x', 30, true);

        assertSame(30, $this->rules->maxPriority($this->kbId));
    }

    public function testCountsByKnowledgeBase(): void
    {
        $this->rules->create($this->kbId, 'A', 'x', 10, true);
        $this->rules->create($this->kbId, 'B', 'x', 20, true);
        $this->rules->create($this->kbId, 'C', 'x', 30, false);

        $counts = $this->rules->countsByKnowledgeBase();
        assertSame(['total' => 3, 'enabled' => 2], $counts[$this->kbId] ?? []);
    }

    private function cleanup(): void
    {
        // ON DELETE CASCADE removes the rules with the knowledge base.
        IntegrationDb::cleanup($this->connection, '{{%knowledge_bases}}', ['slug' => self::SLUG]);
    }
}
