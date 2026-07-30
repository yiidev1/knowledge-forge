<?php

declare(strict_types=1);

use App\Environment;

$basePath = Environment::appBasePath();

return [
    /**
     * Durable log file for the web tier only.
     *
     * Defined here rather than in `config/common/params.php` so the console never picks it up: the two
     * tiers run as different users that are not in each other's groups, and a log written by both would
     * end up unwritable by one of them. `config/common/di/logger.php` adds the file target only when
     * this key is present. Rotation is handled by `/etc/logrotate.d/knowledge-forge`.
     */
    'app/log' => [
        'file' => '@runtime/logs/app.log',
    ],

    /**
     * Session cookie identity.
     *
     * PHP names the session cookie `PHPSESSID` at path `/` by default. When several applications share
     * one hostname — as they do here — that name and path are shared too, so each application
     * overwrites the session id the others just issued and logins are lost on the next request to a
     * neighbour. Naming the cookie and scoping it to this application's mount point makes the cookie
     * unambiguous and keeps it off every other path on the domain.
     */
    'yiisoft/session' => [
        'session' => [
            'options' => [
                'name' => 'KFSESSID',
                'cookie_path' => $basePath === '' ? '/' : $basePath,
                // Restated from the package default because this array overrides it. The deployment
                // is plain HTTP and SessionMiddleware refuses to emit a secure cookie over a non-HTTPS
                // request; raise this to 1 once TLS terminates in front of the application.
                'cookie_secure' => 0,
            ],
        ],
    ],
];
