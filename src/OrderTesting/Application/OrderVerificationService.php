<?php

declare(strict_types=1);

namespace App\OrderTesting\Application;

use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\OrderVerification;
use App\OrderTesting\Domain\OrderVerificationRepositoryInterface;
use App\OrderTesting\Domain\SourceOrderExistenceInterface;
use App\OrderTesting\Domain\TestActorInterface;
use App\OrderTesting\Domain\VerificationAction;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Domain\Exception\ValidationException;

/**
 * Marking a source order as checked over, and taking that back.
 *
 * ## What this refuses, and why each refusal is here rather than in the endpoint
 *
 * **An order that is not this store's.** The pair is the identity; a request naming a store it may
 * reach and an order belonging to somebody else would otherwise write a verification onto a row the
 * reader never saw. {@see SourceOrderExistenceInterface} answers from `order58_orders` by the same pair
 * the rest of the application uses, so "exists" here means exactly what "exists" means on the page.
 *
 * **An agent.** The realm cannot reach these routes at all, so this is unreachable rather than
 * defensive — but the database CHECK admits only ADMIN and OPERATION, and a 500 from a constraint is a
 * worse way to learn that than a sentence. {@see InitiatorType} carries a third case for the practice
 * surface, and this is the seam where that third case stops.
 *
 * ## Who the actor is, and who it is never taken from
 *
 * {@see TestActorInterface}, which the middleware populated from the session it verified. A posted
 * actor id is read by nothing here. An operation user's id is Order58's own `admin_id`, never the local
 * `order58_agents.id` — the port's docblock is the authority on that and this service simply passes it
 * through with the realm beside it.
 *
 * ## Idempotence
 *
 * Verifying an already-verified order is not an error. The click was a statement about the order, the
 * order already says that, and refusing would mean a second reader has to care who got there first.
 * The version still advances and the event is still recorded, so the history shows both people.
 */
final readonly class OrderVerificationService
{
    public function __construct(
        private OrderVerificationRepositoryInterface $verifications,
        private SourceOrderExistenceInterface $orders,
        private ClockInterface $clock,
    ) {}

    public function current(int $storeSourceId, int $sourceOrderId): OrderVerification
    {
        return $this->verifications->find($storeSourceId, $sourceOrderId);
    }

    /**
     * @throws ValidationException                                     when the order or actor is refused
     * @throws \App\OrderTesting\Domain\VerificationConflict           when the version has moved on
     */
    public function apply(
        int $storeSourceId,
        int $sourceOrderId,
        VerificationAction $action,
        TestActorInterface $actor,
        ?int $expectedVersion,
    ): OrderVerification {
        $this->guardOrder($storeSourceId, $sourceOrderId);

        $type = $actor->initiatorType();

        if ($type === InitiatorType::Agent) {
            throw ValidationException::forField(
                'actor',
                'Verification is for administrators and operation users. Nothing was changed.',
            );
        }

        return $this->verifications->apply(
            $storeSourceId,
            $sourceOrderId,
            $action,
            $type,
            $actor->initiatorId(),
            $expectedVersion,
            $this->clock->now(),
        );
    }

    private function guardOrder(int $storeSourceId, int $sourceOrderId): void
    {
        if ($storeSourceId <= 0 || $sourceOrderId <= 0) {
            throw ValidationException::forField('order', 'That is not an order this page can verify.');
        }

        if (!$this->orders->exists($storeSourceId, $sourceOrderId)) {
            // Deliberately the same sentence for "no such order" and "another store's order": telling
            // the second case apart would confirm that somebody else's order exists.
            throw ValidationException::forField(
                'order',
                'That order is not one of this store\'s orders. Nothing was changed.',
            );
        }
    }
}
