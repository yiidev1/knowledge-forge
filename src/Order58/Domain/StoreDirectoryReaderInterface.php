<?php

declare(strict_types=1);

namespace App\Order58\Domain;

/**
 * Reads the admin store directory — mirrored Order58 stores joined to their knowledge bases, with document
 * and knowledge counts — using server-side search, filtering, alphabet bucketing and pagination. The
 * implementation must obtain its rows, total and letter counts without an N+1, so the page issues a bounded
 * number of queries regardless of store volume.
 */
interface StoreDirectoryReaderInterface
{
    public function search(StoreDirectoryQuery $query): StoreDirectoryResult;
}
