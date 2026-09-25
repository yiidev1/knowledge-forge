<?php

declare(strict_types=1);

namespace App\Order58\Web\Calls;

use App\Web\Shared\Layout\Admin\AdminAsset;
use Yiisoft\Assets\AssetBundle;

/**
 * The one script Manage Order58 Calls needs: the select-all checkbox.
 *
 * Everything else on the page is a plain form. Without this file the page still works — every row's
 * checkbox is a real control and Sync is a real submit — so the script is an accelerator for selecting
 * twenty calls at once, not a dependency.
 *
 * A file rather than an inline `<script>` because the content-security policy is `script-src 'self'`
 * with no `unsafe-inline`. No CSS of its own: the badges and table styles it relies on are already in
 * `admin.css`, which is why `AdminAsset` is the dependency.
 */
final class CallsAsset extends AssetBundle
{
    public ?string $basePath = '@assets/order58-calls';
    public ?string $baseUrl = '@assetsUrl/order58-calls';
    public ?string $sourcePath = '@assetsSource/order58-calls';

    public array $depends = [AdminAsset::class];
    public array $js = ['order58-calls.js'];
}
