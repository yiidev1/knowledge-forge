<?php

declare(strict_types=1);

namespace App\OrderTesting\Web;

use Yiisoft\Assets\AssetBundle;

/**
 * The receipt page's own stylesheet.
 *
 * Separate from {@see DemoOrdersAsset} deliberately: the receipt is a white paper document and the
 * comparison is a dark screen one. Sharing a bundle would put each page's rules on the other, and the
 * one change this feature was allowed to make to the comparison page was a button.
 */
final class ReceiptAsset extends AssetBundle
{
    public ?string $basePath = '@assets/order-testing-receipt';
    public ?string $baseUrl = '@assetsUrl/order-testing-receipt';
    public ?string $sourcePath = '@assetsSource/order-testing-receipt';
    public array $css = ['receipt.css'];
    public array $js = ['receipt.js'];
}
