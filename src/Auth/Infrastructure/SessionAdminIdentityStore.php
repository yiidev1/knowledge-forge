<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Application\AdminIdentityStoreInterface;
use Yiisoft\Session\SessionInterface;

/**
 * Keeps the logged-in administrator's id in the PSR session.
 */
final readonly class SessionAdminIdentityStore implements AdminIdentityStoreInterface
{
    private const SESSION_KEY = 'kf.admin_id';

    public function __construct(
        private SessionInterface $session,
    ) {}

    public function store(int $adminId): void
    {
        // Regenerate BEFORE writing the identity: a new session id after authentication defeats session
        // fixation, where an attacker fixes a victim's pre-login session id and rides it afterwards.
        $this->session->regenerateId();
        $this->session->set(self::SESSION_KEY, $adminId);
    }

    public function currentId(): ?int
    {
        $value = $this->session->get(self::SESSION_KEY);

        return is_numeric($value) ? (int) $value : null;
    }

    public function clear(): void
    {
        $this->session->remove(self::SESSION_KEY);
        // Destroy the whole session so no residual data survives a logout, and regenerate so the old
        // cookie value can never be reused.
        $this->session->regenerateId();
    }
}
