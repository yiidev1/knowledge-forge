<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure\Process;

use function preg_replace;
use function sprintf;
use function substr;
use function trim;

final readonly class ProcessResult
{
    private const DIAGNOSTIC_LENGTH = 2000;

    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public bool $timedOut,
    ) {}

    public function isSuccessful(): bool
    {
        return !$this->timedOut && $this->exitCode === 0;
    }

    /**
     * A one-line summary for the log. **Never hand this to a template.**
     *
     * Whitespace is collapsed because ffmpeg and whisper.cpp both use carriage returns to animate
     * progress, and a failure spread over four hundred lines of redrawn status is a failure nobody
     * greps for. One line, capped, is a line that can be found.
     */
    public function diagnostics(): string
    {
        $raw = trim($this->stderr) !== '' ? $this->stderr : $this->stdout;
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $raw));

        if ($collapsed === '') {
            $collapsed = '(empty)';
        }

        return sprintf(
            'exit code %d%s, stderr: %s',
            $this->exitCode,
            $this->timedOut ? ' (timed out)' : '',
            substr($collapsed, 0, self::DIAGNOSTIC_LENGTH),
        );
    }
}
