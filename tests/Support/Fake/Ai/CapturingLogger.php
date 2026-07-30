<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Ai;

use Psr\Log\AbstractLogger;
use Stringable;

use function json_encode;

/**
 * Captures every log record as flat text, so a test can assert that a secret never appears in anything
 * that was logged.
 */
final class CapturingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = (string) $level . ' ' . (string) $message . ' ' . (string) json_encode($context);
    }

    public function everything(): string
    {
        return implode("\n", $this->lines);
    }
}
