<?php

declare(strict_types=1);

namespace App\OrderTesting\Web;

use Yiisoft\Assets\AssetBundle;

/**
 * Styling and the one behaviour of the Practice Attempts page.
 *
 * Its own bundle for the same reason {@see DemoOrdersAsset} has one: these rules are loaded by one
 * page out of the whole application, and putting them in the stylesheet every page downloads would be
 * paying for them everywhere.
 *
 * The script does one thing: it takes over the Retry form so the attempt is recorded and the browser
 * then goes straight to Order58. It cannot be a redirect — `form-action 'self'` is enforced across
 * redirects by Chrome and Safari, so a form that ends at order58.com is blocked. See the file itself.
 */
final class PracticeAttemptsAsset extends AssetBundle
{
    public ?string $basePath = '@assets/practice-attempts';

    public ?string $baseUrl = '@assetsUrl/practice-attempts';

    public ?string $sourcePath = '@assetsSource/practice-attempts';

    public array $css = ['practice-attempts.css'];

    public array $js = ['practice-attempts.js'];
}
