<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\PasswordHasherInterface;
use SensitiveParameter;

/**
 * PASSWORD_DEFAULT-based hashing.
 *
 * The algorithm is deliberately not pinned. PHP's default is bcrypt today and may become argon2id; by
 * deferring to PASSWORD_DEFAULT and pairing it with {@see needsRehash()}, a stored hash is upgraded
 * transparently on the owner's next login, with no migration and no forced reset. The `admin_users`
 * column is VARCHAR(255) so a longer future hash still fits.
 */
final class NativePasswordHasher implements PasswordHasherInterface
{
    public function hash(#[SensitiveParameter] string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_DEFAULT);
    }

    public function verify(#[SensitiveParameter] string $plainPassword, string $hash): bool
    {
        // password_verify is constant-time with respect to the hash and does not reveal, through
        // timing, whether the stored hash was well-formed.
        return password_verify($plainPassword, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}
