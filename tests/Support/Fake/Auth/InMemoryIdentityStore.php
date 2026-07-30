<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Auth;

use App\Auth\Application\AdminIdentityStoreInterface;

/**
 * In-memory identity store recording what the login flow stored, and how many times the session id was
 * regenerated (proxied by store/clear calls) so a test can assert fixation defences ran.
 */
final class InMemoryIdentityStore implements AdminIdentityStoreInterface
{
    public int $storeCalls = 0;
    public int $clearCalls = 0;

    public function __construct(private ?int $currentId = null) {}

    public function store(int $adminId): void
    {
        $this->storeCalls++;
        $this->currentId = $adminId;
    }

    public function currentId(): ?int
    {
        return $this->currentId;
    }

    public function clear(): void
    {
        $this->clearCalls++;
        $this->currentId = null;
    }
}
