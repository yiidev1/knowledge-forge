<?php

declare(strict_types=1);

namespace App\Order58\Application;

/**
 * UI display toggles for Order58-sourced documents (independent of sync behaviour).
 */
final readonly class Order58DisplayParams
{
    public function __construct(
        public bool $showStoreProfileDocuments,
    ) {}
}
