<?php

declare(strict_types=1);

namespace App\Auth\Application;

/**
 * Where the "who is logged in" marker is kept between requests.
 *
 * Abstracted from the session so the login flow does not depend on session mechanics, and so a test can
 * substitute an in-memory store. The stored value is only the administrator's id; the account itself is
 * re-loaded from the repository on each request, so deactivating an admin takes effect immediately.
 */
interface AdminIdentityStoreInterface
{
    /**
     * Records the authenticated administrator. Implementations must regenerate the underlying session id
     * to prevent session fixation.
     */
    public function store(int $adminId): void;

    public function currentId(): ?int;

    /**
     * Clears the identity and abandons the session, so a logout cannot be undone by replaying the old
     * session cookie.
     */
    public function clear(): void;
}
