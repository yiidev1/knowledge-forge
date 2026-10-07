<?php

declare(strict_types=1);

namespace App\OrderTesting\Application;

/** Either a directory this feature may read, or the reason it may not. */
final readonly class DemoOrderDirectoryStatus
{
    private function __construct(
        public ?string $path,
        public ?string $problem,
    ) {}

    public static function usable(string $path): self
    {
        return new self($path, null);
    }

    public static function unusable(string $problem): self
    {
        return new self(null, $problem);
    }

    public function isUsable(): bool
    {
        return $this->path !== null;
    }
}
