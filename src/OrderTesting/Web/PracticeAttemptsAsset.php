<?php

declare(strict_types=1);

namespace App\OrderTesting\Web;

use Yiisoft\Assets\AssetBundle;

/**
 * Styling for the Practice Attempts page.
 *
 * Its own bundle for the same reason {@see DemoOrdersAsset} has one: these rules are loaded by one
 * page out of the whole application, and putting them in the stylesheet every page downloads would be
 * paying for them everywhere.
 *
 * No JavaScript. The page is a server-rendered list of cards — the only interactive thing on it is a
 * form that posts to an endpoint that already exists, and a link that opens a tab.
 */
final class PracticeAttemptsAsset extends AssetBundle
{
    public ?string $basePath = '@assets/practice-attempts';

    public ?string $baseUrl = '@assetsUrl/practice-attempts';

    public ?string $sourcePath = '@assetsSource/practice-attempts';

    public array $css = ['practice-attempts.css'];
}
