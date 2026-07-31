<?php

declare(strict_types=1);

namespace App\Order58\Contract\Exception;

/**
 * The request to Order58 could not be completed (network error or timeout). Transient for a GET.
 */
final class Order58Timeout extends Order58Exception {}
