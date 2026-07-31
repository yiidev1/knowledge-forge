<?php

declare(strict_types=1);

namespace App\Order58\Contract\Exception;

/**
 * The Integration API rejected the Bearer token (401/403). Never retried — a bad token will not fix
 * itself, and repeating the call only wastes the Order58 server's time.
 */
final class Order58AuthenticationFailed extends Order58Exception {}
