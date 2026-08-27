<?php

declare(strict_types=1);

namespace App\AudioToText\Web;

/**
 * Absolute paths to this feature's shared partials.
 *
 * The `@src/...` alias is expanded for a *layout* path but not for a view name, so a partial rendered
 * from inside a template has to be addressed absolutely. Exposing the paths from a class keeps that
 * detail in one place — the same approach `Reports\Web\Chat\ReportViews` and `Chat\Web\ChatPartials`
 * already take.
 */
final class AudioToTextViews
{
    public static function workerStatus(): string
    {
        return __DIR__ . '/_partial/worker-status';
    }
}
