<?php

declare(strict_types=1);

namespace App\Shared\Application\Correlation;

/**
 * Ties together every log record produced by one request or one worker run.
 *
 * A single document ingestion emits records from the upload action, the worker, the OpenAI gateway and
 * the processing-event log. Without a shared id, correlating them in a shared-server log file is
 * guesswork.
 *
 * This is a per-process value object, created once and injected; it is not a static registry.
 */
final class CorrelationId
{
    private string $value;

    public function __construct(?string $value = null)
    {
        $this->value = $value ?? bin2hex(random_bytes(8));
    }

    public function value(): string
    {
        return $this->value;
    }
}
