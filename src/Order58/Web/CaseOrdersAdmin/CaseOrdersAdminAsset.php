<?php

declare(strict_types=1);

namespace App\Order58\Web\CaseOrdersAdmin;

use App\Web\Shared\Layout\Admin\AdminAsset;
use Yiisoft\Assets\AssetBundle;

/**
 * The one script this page needs: View opens a dialog instead of navigating.
 *
 * The page is complete without it. Every View is a real link to a real detail page, so with the script
 * blocked, middle-clicked, or simply failing to load, the feature degrades to exactly the behaviour it
 * had before the dialog existed. That is the reason the markup carries an `href` at all rather than a
 * button the script has to make work.
 *
 * A file rather than an inline `<script>` because the content-security policy is `script-src 'self'`
 * with no `unsafe-inline`. No CSS of its own: the dialog reuses `.a2t-confirm` from `admin.css`, which
 * is what `AdminAsset` brings.
 */
final class CaseOrdersAdminAsset extends AssetBundle
{
    public ?string $basePath = '@assets/order58-calls';
    public ?string $baseUrl = '@assetsUrl/order58-calls';
    public ?string $sourcePath = '@assetsSource/order58-calls';

    public array $depends = [AdminAsset::class];

    public array $js = ['order58-case-order-detail.js'];
}
