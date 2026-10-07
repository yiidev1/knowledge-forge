<?php

declare(strict_types=1);

namespace App\OrderTesting\Web;

use Yiisoft\Assets\AssetBundle;

/**
 * Styling for the demo-order list and the comparison page.
 *
 * Its own bundle and its own file rather than a block in `assets/main/admin.css`, for two reasons. The
 * admin stylesheet's Audio-to-Text section is required by `ModuleIsolationTest` to stay under the
 * `.a2t-` prefix, and adding `.ot-` rules there would either trip that test or depend on being inserted
 * above an invisible marker line. And these styles are loaded by two pages out of the whole
 * application, so putting them in the file every page downloads would be paying for them everywhere.
 *
 * No JavaScript. Both pages are server-rendered tables; nothing here needs a script, and a bundle
 * without one cannot start a timer on a page that does not want it.
 */
final class DemoOrdersAsset extends AssetBundle
{
    public ?string $basePath = '@assets/order-testing-demo';

    public ?string $baseUrl = '@assetsUrl/order-testing-demo';

    public ?string $sourcePath = '@assetsSource/order-testing-demo';

    public array $css = ['demo-orders.css'];
}
