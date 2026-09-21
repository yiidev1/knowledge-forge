<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Store;

use App\Web\Shared\Layout\Admin\AdminAsset;
use Yiisoft\Assets\AssetBundle;

/** Upload and conversion feedback loaded only by the store-audio page. */
final class StoreAudioAsset extends AssetBundle
{
    public ?string $basePath = '@assets/audio-store';
    public ?string $baseUrl = '@assetsUrl/audio-store';
    public ?string $sourcePath = '@assetsSource/audio-store';

    public array $depends = [AdminAsset::class];
    public array $css = ['audio-store.css'];
    public array $js = ['audio-store.js'];
}
