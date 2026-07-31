<?php

declare(strict_types=1);

namespace App\Order58\Contract\Exception;

/**
 * Order58 returned a 5xx server error, or the request failed in transport. Transient: retried per policy.
 */
final class Order58TransportFailed extends Order58Exception {}
