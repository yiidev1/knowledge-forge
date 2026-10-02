<?php

declare(strict_types=1);

namespace App\Order58\Application\Orders;

use RuntimeException;

/**
 * One record could not be understood well enough to store.
 *
 * Thrown per order, never per response: the sync catches it, counts the order as failed and carries on
 * with the rest. One malformed row costing an operator the other 199 would be the wrong trade.
 */
final class OrderNotMappable extends RuntimeException {}
