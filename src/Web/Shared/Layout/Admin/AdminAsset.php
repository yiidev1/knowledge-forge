<?php

declare(strict_types=1);

namespace App\Web\Shared\Layout\Admin;

use Yiisoft\Assets\AssetBundle;

/**
 * Stylesheet for the administrator interface and the login screen.
 *
 * A single self-hosted CSS file, no CDN and no JavaScript dependency, which keeps the strict
 * `script-src 'self'` content-security policy (added in Phase 8) satisfiable without exceptions.
 */
final class AdminAsset extends AssetBundle
{
    public ?string $basePath = '@assets/main';
    public ?string $baseUrl = '@assetsUrl/main';
    public ?string $sourcePath = '@assetsSource/main';

    public array $css = [
        'admin.css',
    ];

    public array $js = [
        'admin.js',
    ];
}
