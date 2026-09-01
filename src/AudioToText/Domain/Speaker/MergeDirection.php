<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

/**
 * Which neighbour a merge would join a turn to.
 *
 * An enum rather than a boolean so the call site reads as a direction, and so the two POST endpoints
 * can validate their `direction` field against something exhaustive.
 */
enum MergeDirection: string
{
    case Previous = 'previous';
    case Next = 'next';
}
