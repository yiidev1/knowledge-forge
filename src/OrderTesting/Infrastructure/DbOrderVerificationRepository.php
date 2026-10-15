<?php

declare(strict_types=1);

namespace App\OrderTesting\Infrastructure;

use App\OrderTesting\Domain\InitiatorType;
use App\OrderTesting\Domain\OrderVerification;
use App\OrderTesting\Domain\OrderVerificationRepositoryInterface;
use App\OrderTesting\Domain\VerificationAction;
use App\OrderTesting\Domain\VerificationConflict;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Query\Query;

use function is_array;
use function is_string;

/**
 * Order verification state and its history, in MySQL.
 *
 * ## One transaction, two tables
 *
 * The state row and the event row are written together or not at all. A history that records a change
 * the state does not show — or a state nobody is recorded as having set — would be worse than keeping
 * no history, because it would be believed.
 *
 * ## The lock, and the two ways it can be lost
 *
 * A writer sends the version they were looking at. An **update** carries it in the WHERE clause, so a
 * row somebody else advanced matches nothing and reports zero affected rows. An **insert** cannot do
 * that — there is no row to put a condition on — so the UNIQUE key does the same job from the other
 * side: a second insert for the same order fails on the key rather than producing a second opinion.
 * Both failures become the same refusal, carrying the state as it actually now is.
 *
 * `version = version + 1` always changes the row, so "zero affected" can only ever mean the condition
 * failed. That matters: MySQL also reports zero when an UPDATE matches but changes nothing, and reading
 * that as a conflict would reject a legitimate repeat of the same decision.
 */
final readonly class DbOrderVerificationRepository implements OrderVerificationRepositoryInterface
{
    private const STATE = '{{%order_testing_order_verifications}}';
    private const EVENTS = '{{%order_testing_verification_events}}';

    public function __construct(private ConnectionInterface $connection) {}

    public function find(int $storeSourceId, int $sourceOrderId): OrderVerification
    {
        $row = (new Query($this->connection))
            ->from(self::STATE)
            ->where([
                'store_source_id' => $storeSourceId,
                'source_order_id' => $sourceOrderId,
            ])
            ->one();

        if (!is_array($row)) {
            return OrderVerification::notYetRecorded($storeSourceId, $sourceOrderId);
        }

        return $this->hydrate($storeSourceId, $sourceOrderId, $row);
    }

    public function apply(
        int $storeSourceId,
        int $sourceOrderId,
        VerificationAction $action,
        InitiatorType $actorType,
        int $actorId,
        ?int $expectedVersion,
        DateTimeImmutable $now,
    ): OrderVerification {
        $verified = $action->verified();
        $at = $now->format('Y-m-d H:i:s');

        /** @var OrderVerification $applied */
        $applied = $this->connection->transaction(
            function () use (
                $storeSourceId,
                $sourceOrderId,
                $action,
                $actorType,
                $actorId,
                $expectedVersion,
                $verified,
                $at,
            ): OrderVerification {
                $current = $this->find($storeSourceId, $sourceOrderId);

                // Checked before the write as well as during it. The WHERE clause below would catch a
                // stale version on its own; doing it here too means the refusal carries the state the
                // writer should have been looking at, rather than a bare failure.
                if ($expectedVersion !== null && $expectedVersion !== $current->version) {
                    throw new VerificationConflict($current);
                }

                $state = [
                    'is_verified' => $verified ? 1 : 0,
                    'verified_at' => $verified ? $at : null,
                    'verified_by_type' => $verified ? $actorType->value : null,
                    'verified_by_id' => $verified ? $actorId : null,
                    'changed_at' => $at,
                    'changed_by_type' => $actorType->value,
                    'changed_by_id' => $actorId,
                    'updated_at' => $at,
                ];

                if ($current->version === 0) {
                    try {
                        $this->connection->createCommand()
                            ->insert(self::STATE, $state + [
                                'store_source_id' => $storeSourceId,
                                'source_order_id' => $sourceOrderId,
                                'version' => 1,
                                'created_at' => $at,
                            ])
                            ->execute();
                    } catch (IntegrityException) {
                        // Somebody inserted the first row between the read above and this write.
                        throw new VerificationConflict($this->find($storeSourceId, $sourceOrderId));
                    }
                } else {
                    $affected = $this->connection->createCommand()
                        ->update(
                            self::STATE,
                            $state + ['version' => $current->version + 1],
                            [
                                'store_source_id' => $storeSourceId,
                                'source_order_id' => $sourceOrderId,
                                'version' => $current->version,
                            ],
                        )
                        ->execute();

                    if ($affected === 0) {
                        throw new VerificationConflict($this->find($storeSourceId, $sourceOrderId));
                    }
                }

                // Append-only. Nothing in this application updates or deletes an event.
                $this->connection->createCommand()
                    ->insert(self::EVENTS, [
                        'store_source_id' => $storeSourceId,
                        'source_order_id' => $sourceOrderId,
                        'action' => $action->value,
                        'actor_type' => $actorType->value,
                        'actor_id' => $actorId,
                        'created_at' => $at,
                    ])
                    ->execute();

                return $this->find($storeSourceId, $sourceOrderId);
            },
        );

        return $applied;
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(int $storeSourceId, int $sourceOrderId, array $row): OrderVerification
    {
        return new OrderVerification(
            storeSourceId: $storeSourceId,
            sourceOrderId: $sourceOrderId,
            isVerified: (int) $row['is_verified'] === 1,
            verifiedAt: $this->moment($row['verified_at'] ?? null),
            verifiedByType: $this->realm($row['verified_by_type'] ?? null),
            verifiedById: $row['verified_by_id'] === null ? null : (int) $row['verified_by_id'],
            changedAt: $this->moment($row['changed_at'] ?? null),
            changedByType: $this->realm($row['changed_by_type'] ?? null),
            changedById: $row['changed_by_id'] === null ? null : (int) $row['changed_by_id'],
            version: (int) $row['version'],
        );
    }

    private function moment(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    private function realm(mixed $value): ?InitiatorType
    {
        return is_string($value) ? InitiatorType::tryFrom($value) : null;
    }
}
