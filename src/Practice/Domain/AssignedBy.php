<?php

declare(strict_types=1);

namespace App\Practice\Domain;

/**
 * Who assigned a topic: a realm and an id that only mean anything together.
 *
 * Built in the web layer from the signed-in actor and never from request input, so a posted
 * `assigned_by_id` is read by nothing at all.
 */
final readonly class AssignedBy
{
    public function __construct(
        public AssignedByType $type,
        public int $id,
    ) {}
}
