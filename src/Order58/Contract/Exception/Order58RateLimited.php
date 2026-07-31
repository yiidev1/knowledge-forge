<?php

declare(strict_types=1);

namespace App\Order58\Contract\Exception;

/**
 * The Integration API returned 429. Transient: retried after the `Retry-After` delay (or backoff).
 */
final class Order58RateLimited extends Order58Exception {}
