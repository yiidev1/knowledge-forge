<?php

declare(strict_types=1);

namespace App\Auth\Application;

use App\Auth\Domain\AdminUser;
use LogicException;

/**
 * The administrator resolved for the current request.
 *
 * Populated once by {@see \App\Auth\Web\Middleware\RequireAdminMiddleware} after it authenticates the
 * request, then read by actions, layouts and services that need to know who is acting. This is
 * request-scoped mutable state: under PHP-FPM one request maps to one process, so the container-shared
 * instance is filled fresh each request and never bleeds between users.
 */
final class CurrentAdmin
{
    private ?AdminUser $admin = null;

    public function setAdmin(AdminUser $admin): void
    {
        $this->admin = $admin;
    }

    public function isAuthenticated(): bool
    {
        return $this->admin !== null;
    }

    /**
     * @throws LogicException when called outside an authenticated request. Actions behind the auth
     *                        middleware can rely on this never throwing; it guards misuse on public
     *                        routes rather than returning a null the caller would forget to check.
     */
    public function get(): AdminUser
    {
        if ($this->admin === null) {
            throw new LogicException('No authenticated administrator in the current request.');
        }

        return $this->admin;
    }

    public function getOrNull(): ?AdminUser
    {
        return $this->admin;
    }
}
