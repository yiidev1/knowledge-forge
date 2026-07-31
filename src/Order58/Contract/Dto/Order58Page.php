<?php

declare(strict_types=1);

namespace App\Order58\Contract\Dto;

/**
 * One page of a list endpoint: the typed records plus the pagination block.
 *
 * @template T of Order58Account|Order58Agent|Order58KnowledgeRecord
 */
final readonly class Order58Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public Order58Pagination $pagination,
    ) {}
}
