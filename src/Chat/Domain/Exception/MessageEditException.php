<?php

declare(strict_types=1);

namespace App\Chat\Domain\Exception;

use App\Shared\Domain\Exception\DomainException;

/**
 * Base for the expected, recoverable failures of editing a question: the question is no longer the latest,
 * the content is unchanged, or a concurrent edit won. These are shown to the user via a flash message (PRG)
 * — unlike a forged/unauthorized target, which is a {@see \App\Shared\Domain\Exception\NotFoundException}
 * and becomes a 404. A web action can catch this one base to surface any of them.
 */
abstract class MessageEditException extends DomainException {}
