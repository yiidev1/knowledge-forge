<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;

/** @var array $params */

/**
 * `StreamTarget` defaults to `php://stdout`, which under PHP-FPM goes nowhere useful — so a web request
 * produced no retrievable log line at all, and a retrieval or grounding decision could not be explained
 * after the fact. Chat responses are not stored at the provider either (`store: false`), which leaves a
 * local file as the only forensic record.
 *
 * The file target is added only when `app/log.file` is set, and that key is defined in
 * `config/web/params.php` alone. Two reasons it is not switched on globally: the web tier runs as the
 * PHP-FPM user while the console runs as the checkout owner, and neither is in the other's group, so a
 * file written by both ends up unwritable by one of them; and console output is already captured by the
 * cron redirect into `runtime/logs/worker.log`.
 *
 * There must be exactly ONE `LoggerInterface` definition across the whole application: the `di-web`
 * group is built as `['$di', 'web/di/*.php']`, so defining it again in a web-only file collides with
 * this one and yiisoft/config rejects the duplicate key while building the container.
 */
$logFile = $params['app/log']['file'] ?? null;

$targets = [Reference::to(StreamTarget::class)];

if ($logFile !== null) {
    $targets[] = DynamicReference::to(
        static fn(Aliases $aliases): StreamTarget => new StreamTarget($aliases->get($logFile)),
    );
}

return [
    LoggerInterface::class => [
        'class' => Logger::class,
        '__construct()' => [
            'targets' => $targets,
        ],
    ],
];
