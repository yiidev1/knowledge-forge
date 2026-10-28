<?php

declare(strict_types=1);

namespace App\Order58\Web\CaseOrders;

use App\Web\Shared\Layout\Admin\AdminAsset;
use Yiisoft\Assets\AssetBundle;

/**
 * The case order synchronisation dialog's script, for every page that renders the dialog.
 *
 * Its own bundle so a page can carry the dialog without the Call Recordings page's other scripts, and
 * so the file is declared once: a page that registered it through two bundles would bind the confirm
 * button twice and post twice. The dialog's styles are already in `admin.css`.
 */
final class CaseOrderSyncAsset extends AssetBundle
{
    public ?string $basePath = '@assets/order58-calls';
    public ?string $baseUrl = '@assetsUrl/order58-calls';
    public ?string $sourcePath = '@assetsSource/order58-calls';

    public array $depends = [AdminAsset::class];

    public array $js = ['order58-case-orders.js'];
}
