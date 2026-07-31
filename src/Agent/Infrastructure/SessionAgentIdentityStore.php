<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure;

use App\Agent\Domain\AgentIdentity;
use App\Agent\Domain\AgentIdentityStoreInterface;
use Yiisoft\Session\SessionInterface;

use function is_array;

/**
 * Stores the safe agent identity in the PSR session under its own key, distinct from the admin key
 * (`kf.admin_id`), so the two realms coexist in the same cookie without collision.
 */
final readonly class SessionAgentIdentityStore implements AgentIdentityStoreInterface
{
    private const SESSION_KEY = 'kf.agent';

    public function __construct(
        private SessionInterface $session,
    ) {}

    public function store(AgentIdentity $identity): void
    {
        $this->session->regenerateId();
        $this->session->set(self::SESSION_KEY, $identity->toArray());
    }

    public function current(): ?AgentIdentity
    {
        $value = $this->session->get(self::SESSION_KEY);

        return is_array($value) ? AgentIdentity::fromArray($value) : null;
    }

    public function clear(): void
    {
        $this->session->remove(self::SESSION_KEY);
        $this->session->regenerateId();
    }
}
