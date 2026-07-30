<?php

declare(strict_types=1);

use App\Environment;

// Mount point of the application, empty when it owns the domain root. Assets and the favicon are
// plain static files served by the front end, so their URLs are built from this alias rather than
// from the router; `BasePathMiddleware` handles the routed half from the same value.
$basePath = Environment::appBasePath();

return [
    '@root' => dirname(__DIR__, 2),
    '@src' => '@root/src',
    '@assets' => '@root/public/assets',
    '@assetsUrl' => '@baseUrl/assets',
    '@assetsSource' => '@root/assets',
    '@baseUrl' => $basePath === '' ? '/' : $basePath,
    '@public' => '@root/public',
    '@runtime' => '@root/runtime',
    '@vendor' => '@root/vendor',
];
