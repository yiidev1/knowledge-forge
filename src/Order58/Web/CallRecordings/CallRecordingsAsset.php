<?php

declare(strict_types=1);

namespace App\Order58\Web\CallRecordings;

use App\Web\Shared\Layout\Admin\AdminAsset;
use Yiisoft\Assets\AssetBundle;

/**
 * The one script this page needs: the select-all checkbox.
 *
 * ## Why it points at the calls page's script
 *
 * Both pages present the same control — a day's calls, each with a checkbox, and a header box that
 * selects them all — so they publish the same `data-o58-*` attributes and `order58-calls.js` drives
 * either one unchanged. A second copy of that file would be a second place to fix the next bug in it.
 *
 * This bundle exists rather than depending on {@see \App\Order58\Web\Calls\CallsAsset} directly so that
 * the two pages' asset wiring can diverge later without one of them quietly inheriting the other's
 * scripts. Same source directory today, separate declarations.
 *
 * The page works without the file: every row carries a real checkbox and Download is a real submit, so
 * the only thing lost is selecting twenty calls in one click. A file rather than an inline `<script>`
 * because the content-security policy is `script-src 'self'` with no `unsafe-inline`. No CSS of its own
 * — the badges and table styles are already in `admin.css`, which is why `AdminAsset` is the dependency.
 */
final class CallRecordingsAsset extends AssetBundle
{
    public ?string $basePath = '@assets/order58-calls';
    public ?string $baseUrl = '@assetsUrl/order58-calls';
    public ?string $sourcePath = '@assetsSource/order58-calls';

    public array $depends = [AdminAsset::class];

    /**
     * The shared select-all, and this page's own live progress.
     *
     * `order58-recordings.js` is not shared: the calls page has nothing to poll, and giving it a script
     * that looks for a status endpoint it does not render would be dead weight on every load.
     */
    public array $js = ['order58-calls.js', 'order58-recordings.js'];
}
