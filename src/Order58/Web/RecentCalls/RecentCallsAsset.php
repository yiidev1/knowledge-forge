<?php

declare(strict_types=1);

namespace App\Order58\Web\RecentCalls;

use App\Web\Shared\Layout\Admin\AdminAsset;
use Yiisoft\Assets\AssetBundle;

/**
 * The Recent Calls page's table and player, loaded only by that page.
 *
 * A file rather than an inline `<script>` because the content-security policy is `script-src 'self'`
 * with no `'unsafe-inline'`. Its own small stylesheet for the Play and Download controls, which mirror
 * the Manage Orders page's look without loading that page's (or Audio-to-Text's) scripts and styles.
 */
final class RecentCallsAsset extends AssetBundle
{
    public ?string $basePath = '@assets/recent-calls';
    public ?string $baseUrl = '@assetsUrl/recent-calls';
    public ?string $sourcePath = '@assetsSource/recent-calls';

    public array $depends = [AdminAsset::class];
    public array $css = ['recent-calls.css'];
    public array $js = ['recent-calls.js'];
}
