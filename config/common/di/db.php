<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Db\DbConnectionFactory;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;

return [
    // yiisoft/cache binds Psr\SimpleCache\CacheInterface to ArrayCache, so the schema cache lives for
    // one request or one worker run. That is the right trade here: table metadata is read a handful of
    // times per process, whereas a persistent cache would keep serving stale columns straight after a
    // migration until someone remembered to flush it.
    SchemaCache::class => static fn(SimpleCacheInterface $cache): SchemaCache => new SchemaCache($cache),

    // Connection settings live in DbConnectionFactory so that `./yii kf:health` exercises the same code
    // path as the application instead of building a lookalike connection of its own.
    ConnectionInterface::class => static fn(DbConnectionFactory $factory): ConnectionInterface => $factory->create(),
];
