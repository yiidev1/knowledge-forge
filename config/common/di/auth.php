<?php

declare(strict_types=1);

use App\Auth\Application\LoginThrottleInterface;
use App\Auth\Application\ThrottleParams;
use App\Auth\Domain\AdminUserRepositoryInterface;
use App\Auth\Domain\PasswordHasherInterface;
use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\DbLoginThrottle;
use App\Auth\Infrastructure\NativePasswordHasher;

/** @var array $params */

// Bindings needed by both the web app and the console (kf:admin:create uses the repository and hasher).
// The session-backed identity store is web-only and lives in config/web/di/auth.php.
return [
    AdminUserRepositoryInterface::class => DbAdminUserRepository::class,
    PasswordHasherInterface::class => NativePasswordHasher::class,
    LoginThrottleInterface::class => DbLoginThrottle::class,

    ThrottleParams::class => [
        '__construct()' => [
            'maxAttempts' => $params['app/auth']['maxLoginAttempts'],
            'windowMinutes' => $params['app/auth']['loginWindowMinutes'],
            'lockoutMinutes' => $params['app/auth']['loginLockoutMinutes'],
        ],
    ],
];
