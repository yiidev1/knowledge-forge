<?php

declare(strict_types=1);

namespace App\Order58\Contract\Exception;

/**
 * Order58 rejected the request with a non-retryable 4xx (validation, not-found, etc.).
 */
final class Order58RequestFailed extends Order58Exception {}
