<?php

declare(strict_types=1);

namespace App\Order58\Contract\Exception;

/**
 * Order58 returned a body that could not be understood: not JSON, `success` not true, a missing `data`
 * envelope, or a record without its required stable identifier. Never retried — the shape will not change
 * on a resend.
 */
final class Order58InvalidResponse extends Order58Exception {}
