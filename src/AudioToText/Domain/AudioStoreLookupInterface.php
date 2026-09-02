<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * One store, by the id in the URL.
 *
 * Deliberately not a search: the store list is Order58's page and uses Order58's directory reader,
 * which already knows how to search, filter, bucket by letter and paginate. This module only needs to
 * name the store whose audio page it is rendering, so it borrows nothing and duplicates nothing but a
 * lookup.
 */
interface AudioStoreLookupInterface
{
    public function findBySourceId(int $sourceId): ?AudioStore;
}
