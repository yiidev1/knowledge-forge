<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * Hashing and verification of passwords, abstracted so the algorithm is a deployment detail.
 *
 * The implementation uses PASSWORD_DEFAULT and reports when a stored hash should be recomputed, so the
 * application follows PHP's evolving default (bcrypt today, whatever tomorrow) without a code change or
 * a forced password reset.
 */
interface PasswordHasherInterface
{
    /**
     * @return string An opaque hash string safe to persist in a VARCHAR(255) column.
     */
    public function hash(string $plainPassword): string;

    /**
     * Constant-time verification. Implementations must not short-circuit in a way that leaks whether the
     * username existed versus the password was wrong.
     */
    public function verify(string $plainPassword, string $hash): bool;

    /**
     * True when $hash was produced by an algorithm or cost weaker than the current default, so the
     * caller can transparently upgrade it on the next successful login.
     */
    public function needsRehash(string $hash): bool;
}
