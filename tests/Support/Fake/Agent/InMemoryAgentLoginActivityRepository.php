<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Agent;

use App\Agent\Domain\AgentIdentity;
use App\Agent\Domain\AgentLoginActivity;
use App\Agent\Domain\AgentLoginActivityRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use RuntimeException;
use Throwable;

use function array_values;
use function usort;

/**
 * In-memory agent login activity for unit tests.
 *
 * Keyed by `agentAdminId` — the real table's unique key — so "a second login updates rather than inserts" is
 * a property of the fake too, not something each test has to assert around. It mirrors the real upsert
 * exactly: `firstLoginAt` is set once, `lastLoginAt` and the counter move, the name snapshot refreshes.
 */
final class InMemoryAgentLoginActivityRepository implements AgentLoginActivityRepositoryInterface
{
    /** @var array<int, AgentLoginActivity> */
    private array $rows = [];

    /** @var list<int> Every recorded login, in order — proves an update did not insert a second row. */
    public array $writes = [];

    /** Set to make the next write throw, so a test can prove a failure never blocks authentication. */
    public ?Throwable $failWith = null;

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    public function recordSuccessfulLogin(AgentIdentity $identity): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $now = $this->clock->now();
        $existing = $this->rows[$identity->adminId] ?? null;

        $this->rows[$identity->adminId] = new AgentLoginActivity(
            agentAdminId: $identity->adminId,
            username: $identity->username,
            displayName: $identity->displayName,
            // Written once and preserved for good, exactly like the column absent from the UPDATE list.
            firstLoginAt: $existing?->firstLoginAt ?? $now,
            lastLoginAt: $now,
            loginCount: ($existing?->loginCount ?? 0) + 1,
        );

        $this->writes[] = $identity->adminId;
    }

    public function findByAgent(int $agentAdminId): ?AgentLoginActivity
    {
        return $this->rows[$agentAdminId] ?? null;
    }

    public function findAll(): array
    {
        $all = array_values($this->rows);
        usort($all, static fn(AgentLoginActivity $a, AgentLoginActivity $b): int
            => $b->lastLoginAt <=> $a->lastLoginAt);

        return $all;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * A failure the login path must survive.
     */
    public static function unavailable(): RuntimeException
    {
        return new RuntimeException('Database unavailable.');
    }
}
