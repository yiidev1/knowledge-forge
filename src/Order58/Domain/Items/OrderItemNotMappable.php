<?php

declare(strict_types=1);

namespace App\Order58\Domain\Items;

use RuntimeException;

/**
 * One item in the response could not be mapped.
 *
 * Fatal to the whole response, never to one line. The result of a successful fetch **replaces** an
 * order's item set, so storing four of five items would silently delete the fifth — and nothing on the
 * page would say so. The order stays `FAILED` with its previous snapshot intact.
 */
final class OrderItemNotMappable extends RuntimeException {}
