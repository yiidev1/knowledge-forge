<?php

declare(strict_types=1);

namespace App\OrderTesting\Infrastructure;

use App\OrderTesting\Domain\StoreReaderInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_string;

/** Reads the mirrored store table by name; see {@see DbSourceOrderReader} for why by name. */
final readonly class DbStoreReader implements StoreReaderInterface
{
    private const STORES = '{{%order58_stores}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function nameOf(int $sourceId): ?string
    {
        /** @var mixed $name */
        $name = (new Query($this->connection))
            ->select('name')
            ->from(self::STORES)
            ->where(['source_id' => $sourceId])
            ->scalar();

        return is_string($name) && $name !== '' ? $name : null;
    }
}
