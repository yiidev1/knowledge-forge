<?php

declare(strict_types=1);

namespace App\Order58\Contract\Dto;

/**
 * The result of a `GET /health` probe, for the admin "Check Connection" action.
 */
final readonly class Order58Health
{
    public function __construct(
        public bool $ok,
        public ?string $apiVersion,
        public ?string $message,
    ) {}
}
