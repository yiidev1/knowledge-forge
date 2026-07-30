<?php

declare(strict_types=1);

namespace App\Ai\OpenAi;

/**
 * Timeout and retry budget for one class of OpenAI traffic.
 *
 * Two instances exist, and they exist separately because the two callers have opposite constraints:
 *
 * - `chat`   runs inside a web request. Its whole budget has to fit under the Nginx
 *            `fastcgi_read_timeout`, so it gets a short timeout and at most one retry.
 * - `worker` runs from cron with nothing waiting on it. It can afford a long timeout and several
 *            retries, because giving up early just means the document sits queued for another minute.
 *
 * Sharing one setting between them would force the worker to be as impatient as the browser, or the
 * browser to be as patient as the worker.
 */
final readonly class OpenAiHttpProfile
{
    public function __construct(
        public string $name,
        public int $connectTimeoutSeconds,
        public int $timeoutSeconds,
        public int $maxRetries,
        public int $maxBackoffSeconds,
    ) {}

    /**
     * Worst-case wall time for a fully retried request, used by the health command to check the budget
     * against the configured web-server timeout.
     */
    public function worstCaseSeconds(): int
    {
        return ($this->timeoutSeconds + $this->connectTimeoutSeconds) * ($this->maxRetries + 1)
            + $this->maxBackoffSeconds * $this->maxRetries;
    }
}
