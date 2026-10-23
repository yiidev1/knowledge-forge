<?php

declare(strict_types=1);

namespace App\Agent\Web\PracticeOrders;

use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Web\PractitionerTries\Action as OrderTestingPractitionerTriesAction;

/**
 * An agent's own tries, and nobody else's.
 *
 * ## This override is the authorization
 *
 * The practitioner is named in the path — realm and id — so without a check an agent could read a
 * colleague's results by editing the address. {@see admits()} is that check, and it is server-side
 * and absolute: the endpoint refuses before a single result is fetched.
 *
 * Both halves are compared. `admin_users.id = 1` and Order58's `admin_id = 1` are different people
 * in this installation, so an id-only comparison would let one realm read the other's history.
 *
 * A refusal answers `not found`, in the same words as a practitioner who genuinely has no results
 * here: a reader who may not see somebody must not learn from the answer that they exist.
 */
final readonly class PractitionerTriesAction extends OrderTestingPractitionerTriesAction
{
    protected function admits(InitiatorType $realm, int $actorId): bool
    {
        return $realm === $this->actor->initiatorType()
            && $actorId === $this->actor->initiatorId();
    }

    protected function surfaceRoutes(): array
    {
        return [
            'page' => 'agent.practice-orders',
            'pageLabel' => 'Practice Orders',
            'store' => 'agent.practice-orders.store',
            'self' => 'agent.practice-orders.demo-orders.tries',
            'demoOrders' => 'agent.practice-orders.demo-orders',
            'demoCompare' => 'agent.practice-orders.demo-compare',
        ];
    }

    protected function layoutPath(): string
    {
        return '@src/Agent/Web/Layout/layout.php';
    }
}
