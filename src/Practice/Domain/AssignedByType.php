<?php

declare(strict_types=1);

namespace App\Practice\Domain;

/**
 * Which realm assigned a topic.
 *
 * Administrators and operation users are numbered by different systems — `admin_users.id` and
 * Order58's own `admin_id` — so the id is meaningless without the realm beside it. The same shape
 * `order_testing_attempts` already uses for `initiated_by_*`, and for the same reason: an id alone
 * would eventually name the wrong person.
 *
 * Agents are deliberately absent. Assigning a topic is not something an agent may do, and the database
 * CHECK says so too, so a bug here cannot become a row.
 */
enum AssignedByType: string
{
    case Admin = 'ADMIN';
    case Operation = 'OPERATION';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Operation => 'Operation',
        };
    }
}
