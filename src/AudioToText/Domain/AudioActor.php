<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

use InvalidArgumentException;

/**
 * Who performed a piece of audio work — carried as one value so the realm can never be separated from
 * the id.
 *
 * ## Why a value object and not two parameters
 *
 * These columns used to be a bare `int $uploadedByAdminId`. A second realm makes that shape unsafe:
 * `admin_users.id = 7` and Order58's `admin_id = 7` are different people, so a number on its own no
 * longer identifies anyone. Passing the pair as two arguments would work until the day somebody passed
 * the right id with the wrong realm — a mistake that writes a plausible row and cannot be detected
 * afterwards.
 *
 * The constructor is private and the two named constructors each set exactly one id, so an actor that
 * claims both realms, or neither, cannot be built at all. The pair CHECK on each audio table enforces
 * the same rule in the database, which is what catches anything written by a route this class never saw.
 *
 * ## Usable away from a request
 *
 * The worker and the console create jobs too, with no session to ask. They build an administrator actor
 * from the id they already hold, exactly as before — {@see AudioActorInterface} is the request-scoped
 * way to obtain one, not the only way.
 */
final readonly class AudioActor
{
    private function __construct(
        public AudioActorType $type,
        public ?int $adminId,
        public ?int $operationId,
    ) {}

    /** @param int $adminId `admin_users.id` */
    public static function admin(int $adminId): self
    {
        if ($adminId <= 0) {
            throw new InvalidArgumentException('An administrator actor needs a positive admin_users id.');
        }

        return new self(AudioActorType::Admin, $adminId, null);
    }

    /**
     * @param int $order58AdminId Order58's own `admin_id` — the identity that survives a rebuild of the
     *                            `order58_agents` mirror, never `order58_agents.id`
     */
    public static function operation(int $order58AdminId): self
    {
        if ($order58AdminId <= 0) {
            throw new InvalidArgumentException('An operation actor needs a positive Order58 admin_id.');
        }

        return new self(AudioActorType::Operation, null, $order58AdminId);
    }

    /** The actor the current request resolved, for the web paths that have one. */
    public static function fromPort(AudioActorInterface $port): self
    {
        return match ($port->actorType()) {
            AudioActorType::Admin => self::admin((int) $port->adminId()),
            AudioActorType::Operation => self::operation((int) $port->operationId()),
        };
    }

    /**
     * The id, whichever realm it belongs to — for the tables that keep one polymorphic column rather
     * than a pair, such as `audio_segment_revisions`.
     */
    public function id(): int
    {
        return (int) ($this->adminId ?? $this->operationId);
    }

    public function isAdmin(): bool
    {
        return $this->type === AudioActorType::Admin;
    }
}
