<?php

declare(strict_types=1);

namespace App\Ai\Contract\Exception;

/**
 * AiAuthenticationFailed. See {@see AiException} for shared behaviour; the {@see \App\Ai\Contract\Dto\AiErrorDetails}
 * carries the specifics.
 */
final class AiAuthenticationFailed extends AiException {}
