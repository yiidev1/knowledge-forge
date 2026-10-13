<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure;

use App\Agent\Domain\AgentEligibilityReaderInterface;
use App\Agent\Domain\Order58Role;
use Psr\Log\LoggerInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function is_array;

/**
 * Re-reads one mirrored Order58 user's role and status, by `admin_id`.
 *
 * One indexed lookup — `ux_order58_agents_admin` makes `admin_id` UNIQUE, so this is a single-row fetch
 * on a unique key and is cheap enough to run on every protected request, which is exactly what the
 * administrator gate already does against `admin_users`.
 *
 * **Fails closed.** A database error returns null, which the caller reads as "not allowed". Signing
 * someone out because the database blinked is recoverable; letting a revoked account through because it
 * did is not.
 */
final readonly class DbAgentEligibilityReader implements AgentEligibilityReaderInterface
{
    private const AGENTS = '{{%order58_agents}}';
    private const REQUIRED_STATUS = 'active';

    public function __construct(
        private ConnectionInterface $connection,
        private LoggerInterface $logger,
    ) {}

    public function currentRoleFor(int $order58AdminId): ?Order58Role
    {
        if ($order58AdminId <= 0) {
            return null;
        }

        try {
            $row = (new Query($this->connection))
                ->select(['status', 'user_type'])
                ->from(self::AGENTS)
                ->where(['admin_id' => $order58AdminId])
                ->one();
        } catch (Throwable $e) {
            // No identifier in the context: SafeLogContext allowlists keys, and the correlation id
            // already ties this line to the request.
            $this->logger->warning('An Order58 user\'s eligibility could not be re-read.', [
                'reason' => 'agent_eligibility_read_failed',
                'error_class' => $e::class,
            ]);

            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        if ((string) ($row['status'] ?? '') !== self::REQUIRED_STATUS) {
            return null;
        }

        // Exact-case match against the provider's vocabulary. `PreTraining` is a real value in this
        // mirror, so normalising case before comparing would be a way to admit types never approved.
        return Order58Role::fromUserType((string) ($row['user_type'] ?? ''));
    }
}
