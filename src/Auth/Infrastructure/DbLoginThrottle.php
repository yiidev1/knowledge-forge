<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Application\LoginThrottleInterface;
use App\Auth\Application\ThrottleParams;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Infrastructure\Db\DbDateTime;
use Yiisoft\Db\Connection\ConnectionInterface;

use function is_array;
use function max;

/**
 * Database-backed login throttle using a fixed-window counter per key.
 *
 * A failure increments the counter for the current window. When it reaches the configured maximum the
 * key is locked for the lockout period. A window that has already elapsed is reset rather than
 * extended, so an honest user who mistyped a password an hour ago is not treated as an attacker now.
 */
final readonly class DbLoginThrottle implements LoginThrottleInterface
{
    private const TABLE = '{{%auth_login_attempts}}';

    public function __construct(
        private ConnectionInterface $connection,
        private ClockInterface $clock,
        private ThrottleParams $params,
    ) {}

    public function retryAfterSeconds(string $key): ?int
    {
        $row = $this->find($key);

        if ($row === null || $row['locked_until'] === null) {
            return null;
        }

        $lockedUntil = DbDateTime::parse((string) $row['locked_until']);
        $seconds = $lockedUntil->getTimestamp() - $this->clock->now()->getTimestamp();

        return $seconds > 0 ? $seconds : null;
    }

    public function registerFailure(string $key): void
    {
        $now = $this->clock->now();
        $row = $this->find($key);

        // No prior record, or the previous window has fully elapsed: start a fresh window.
        if ($row === null || $this->windowExpired($row, $now->getTimestamp())) {
            $attempts = 1;
            $lockedUntil = $attempts >= $this->params->maxAttempts
                ? DbDateTime::format($now->modify("+{$this->params->lockoutMinutes} minutes"))
                : null;

            $this->connection->createCommand()->upsert(self::TABLE, [
                'attempt_key' => $key,
                'attempts' => $attempts,
                'window_started_at' => DbDateTime::format($now),
                'locked_until' => $lockedUntil,
                'updated_at' => DbDateTime::format($now),
            ])->execute();

            return;
        }

        $attempts = (int) $row['attempts'] + 1;
        $lockedUntil = $attempts >= $this->params->maxAttempts
            ? DbDateTime::format($now->modify("+{$this->params->lockoutMinutes} minutes"))
            : self::asNullableString($row['locked_until']);

        $this->connection->createCommand()->update(
            self::TABLE,
            [
                'attempts' => $attempts,
                'locked_until' => $lockedUntil,
                'updated_at' => DbDateTime::format($now),
            ],
            ['attempt_key' => $key],
        )->execute();
    }

    public function clear(string $key): void
    {
        $this->connection->createCommand()->delete(self::TABLE, ['attempt_key' => $key])->execute();
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function find(string $key): ?array
    {
        $row = $this->connection
            ->createQuery()
            ->from(self::TABLE)
            ->where(['attempt_key' => $key])
            ->limit(1)
            ->one();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function windowExpired(array $row, int $nowTs): bool
    {
        // A live lock keeps the window open until it expires, regardless of the counting window.
        if ($row['locked_until'] !== null) {
            $lockedUntilTs = DbDateTime::parse((string) $row['locked_until'])->getTimestamp();
            if ($lockedUntilTs > $nowTs) {
                return false;
            }
        }

        $windowStartedTs = DbDateTime::parse((string) $row['window_started_at'])->getTimestamp();
        $windowLength = $this->params->windowMinutes * 60;

        return ($nowTs - $windowStartedTs) >= max(1, $windowLength);
    }

    private static function asNullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
