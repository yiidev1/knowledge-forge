<?php

declare(strict_types=1);

namespace App\Practice\Web\Assign;

use App\Practice\Domain\AssignedBy;
use App\Practice\Domain\AssignedByType;
use App\Shared\Web\Actor\CurrentActor;

/**
 * Who is assigning, taken from the signed-in actor and never from the request body.
 *
 * `CurrentActor` is written only by the middleware that already proved the identity, and it throws
 * rather than guess when nothing resolved it. So a route wired without a gate fails loudly here
 * instead of writing an assignment credited to nobody — or, worse, to an administrator who was not
 * involved.
 *
 * Agents are absent by construction: they cannot reach these routes, and the database CHECK on
 * `assigned_by_type` would refuse the row even if they could.
 */
final readonly class ActorFromRequest
{
    public function __construct(private CurrentActor $actor) {}

    public function assignedBy(): AssignedBy
    {
        return $this->actor->isOperation()
            ? new AssignedBy(AssignedByType::Operation, (int) $this->actor->operationId())
            : new AssignedBy(AssignedByType::Admin, (int) $this->actor->adminId());
    }
}
