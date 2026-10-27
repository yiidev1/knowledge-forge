<?php

declare(strict_types=1);

namespace App\Order58\Web\OrderApiViewer;

use App\Web\Shared\Layout\Admin\AdminAsset;
use Yiisoft\Assets\AssetBundle;

/**
 * Client-side paging for the viewer's table, loaded only by that page.
 *
 * Its own bundle rather than lines added to `admin.js`, for the same reason
 * {@see \App\Order58\Web\TestRecordingChannels\RecordingChannelsAsset} has one: this is a single page's
 * table behaviour, and the global script is loaded by every administrator screen.
 *
 * A file rather than an inline `<script>` because the content-security policy is `script-src 'self'`
 * with no `'unsafe-inline'`. No CSS of its own — it only toggles `hidden`, so `AdminAsset` is the
 * dependency purely for the table styling the markup already uses.
 */
final class OrderApiViewerAsset extends AssetBundle
{
    public ?string $basePath = '@assets/order-api-viewer';
    public ?string $baseUrl = '@assetsUrl/order-api-viewer';
    public ?string $sourcePath = '@assetsSource/order-api-viewer';

    public array $depends = [AdminAsset::class];
    public array $js = ['order-api-viewer.js'];
}
