<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Shared;

use Stringable;
use Yiisoft\Router\UrlGeneratorInterface;

/**
 * Minimal URL generator for action tests: returns the route name as a path. Enough for PRG redirects
 * where the exact URL is irrelevant to the assertion.
 */
final class StubUrlGenerator implements UrlGeneratorInterface
{
    public function generate(string $name, array $arguments = [], array $queryParameters = [], ?string $hash = null): string
    {
        return '/' . $name;
    }

    public function generateAbsolute(
        string $name,
        array $arguments = [],
        array $queryParameters = [],
        ?string $hash = null,
        ?string $scheme = null,
        ?string $host = null,
    ): string {
        return 'http://localhost/' . $name;
    }

    public function generateFromCurrent(
        array $replacedArguments,
        array $queryParameters = [],
        ?string $hash = null,
        ?string $fallbackRouteName = null,
    ): string {
        return '/current';
    }

    public function getUriPrefix(): string
    {
        return '';
    }

    public function setUriPrefix(string $name): void {}

    public function setDefaultArgument(string $name, bool|float|int|string|Stringable|null $value): void {}
}
