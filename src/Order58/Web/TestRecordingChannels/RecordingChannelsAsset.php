<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingChannels;

use App\Web\Shared\Layout\Admin\AdminAsset;
use Yiisoft\Assets\AssetBundle;

/**
 * The Time field's client-side validation, loaded only by this page.
 *
 * Its own bundle rather than a few lines added to `admin.js`: this is one diagnostic page's form
 * behaviour, and the global script is loaded by every administrator screen in the application.
 *
 * A separate file rather than an inline `<script>` because the content-security policy is
 * `script-src 'self'` with no 'unsafe-inline'. No CSS of its own — the error styling it applies
 * (`field__control--error`, `field__error`) is already defined in `admin.css`, which is why AdminAsset
 * is the dependency.
 */
final class RecordingChannelsAsset extends AssetBundle
{
    public ?string $basePath = '@assets/recording-channels';
    public ?string $baseUrl = '@assetsUrl/recording-channels';
    public ?string $sourcePath = '@assetsSource/recording-channels';

    public array $depends = [AdminAsset::class];
    public array $js = ['recording-channels.js'];
}
