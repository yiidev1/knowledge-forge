<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * How the worker that last ran was invoked.
 *
 * The worker reports this itself rather than the application inferring it from configuration, which
 * matters because the deployment can change without the application's settings changing at all —
 * installing a systemd timer does not touch `.env`. Reading it from the heartbeat means the admin page
 * describes what is actually happening on the machine.
 */
enum WorkerMode: string
{
    /** A long-running `kf:audio:worker` process. */
    case CONTINUOUS = 'CONTINUOUS';
    /** A `--once` tick from a systemd timer or cron. */
    case ONCE = 'ONCE';

    public static function fromStorage(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::CONTINUOUS;
    }
}
