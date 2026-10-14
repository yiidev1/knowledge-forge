<?php

declare(strict_types=1);

namespace App\Practice\Web;

use Yiisoft\Assets\AssetBundle;

/**
 * Topic Management and Practice Orders styling, and the dialog behaviour they share.
 *
 * Its own bundle rather than additions to `admin.css`: these rules are loaded by three pages out of
 * the whole application, and the shared sheet is prefix-policed by an isolation test.
 */
final class PracticeAsset extends AssetBundle
{
    public ?string $basePath = '@assets/practice';
    public ?string $baseUrl = '@assetsUrl/practice';
    public ?string $sourcePath = '@assetsSource/practice';

    public array $css = ['practice.css'];
    public array $js = ['practice.js'];
}
