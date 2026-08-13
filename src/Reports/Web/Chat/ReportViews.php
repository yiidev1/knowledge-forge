<?php

declare(strict_types=1);

namespace App\Reports\Web\Chat;

/**
 * Absolute filesystem path to the report's shared partial.
 *
 * Absolute for the same reason as {@see \App\Chat\Web\ChatPartials}: a `@src/...` alias is not expanded in a
 * view name, and an action that sets only a layout has no view path to resolve against — the page dies with
 * "The view path is not set.".
 */
final class ReportViews
{
    /** The read-only drill-down / detail dialog. */
    public static function modal(): string
    {
        return __DIR__ . '/_partial/report-modal';
    }
}
