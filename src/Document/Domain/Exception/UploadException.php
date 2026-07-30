<?php

declare(strict_types=1);

namespace App\Document\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

/**
 * Base for all upload failures.
 *
 * Every subclass carries a stable error code and a message already safe to show a user — the upload
 * form re-renders it directly. Distinct subclasses let the web layer treat, say, a duplicate
 * differently from an oversized file without string-matching messages.
 */
abstract class UploadException extends DomainException {}
