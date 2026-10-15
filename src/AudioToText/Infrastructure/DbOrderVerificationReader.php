<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure;

use App\AudioToText\Domain\OrderVerificationReaderInterface;
use App\AudioToText\Domain\OrderVerificationStatus;
use App\Shared\Application\Time\AppTimeZone;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function array_values;
use function is_array;
use function is_string;
use function preg_match;

/**
 * Reads the order-verification state table, by table name.
 *
 * The same arrangement {@see DbTopicAssignmentReader} and {@see DbDemoOrderCountReader} use: this
 * module may not name the one that owns the table, and a name in a string is not a dependency.
 *
 * `audio_conversations.order_id` is operator-typed free text, so a row whose order id is not a plain
 * number is skipped before anything is cast — the guard every reader beside this one applies.
 *
 * ## Why the line says a realm and not a name
 *
 * Resolving "who" would mean joining two user tables and conditioning each join on the realm, because
 * `admin_users.id = 7` and Order58's `admin_id = 7` are different people — a join that forgets the
 * condition shows one person's name against the other's work, which is a defect this application has
 * already had once. The identity is recorded in full on the row and in the event log; the page says
 * the realm and the moment, which is what a reader scanning twenty rows actually uses.
 */
final readonly class DbOrderVerificationReader implements OrderVerificationReaderInterface
{
    private const TABLE = '{{%order_testing_order_verifications}}';

    public function __construct(
        private ConnectionInterface $connection,
        private AppTimeZone $appTimeZone,
    ) {}

    public function forOrders(int $storeSourceId, array $orderIds): array
    {
        /** @var array<array-key, int> $numeric the id as the caller asked it => the number it denotes */
        $numeric = [];

        foreach ($orderIds as $id) {
            if (preg_match('/\A[1-9][0-9]{0,18}\z/', $id) === 1) {
                $numeric[$id] = (int) $id;
            }
        }

        if ($numeric === []) {
            return [];
        }

        $rows = (new Query($this->connection))
            ->select(['source_order_id', 'is_verified', 'version', 'verified_at', 'verified_by_type'])
            ->from(self::TABLE)
            ->where([
                'store_source_id' => $storeSourceId,
                'source_order_id' => array_values($numeric),
            ])
            ->all();

        $byNumber = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $verified = (int) $row['is_verified'] === 1;

            $byNumber[(int) $row['source_order_id']] = new OrderVerificationStatus(
                isVerified: $verified,
                version: (int) $row['version'],
                verifiedLine: $verified ? $this->line($row['verified_by_type'], $row['verified_at']) : null,
            );
        }

        $out = [];

        foreach ($numeric as $raw => $number) {
            if (isset($byNumber[$number])) {
                $out[(string) $raw] = $byNumber[$number];
            }
        }

        return $out;
    }

    private function line(mixed $realm, mixed $at): ?string
    {
        if (!is_string($at) || $at === '') {
            return null;
        }

        // Formatted in the application's timezone, like every other moment this page prints: the
        // browser formatting the instant itself would date the same verification differently for a
        // reader in another country.
        $when = $this->appTimeZone->format(new DateTimeImmutable($at));

        $who = match (is_string($realm) ? $realm : '') {
            'OPERATION' => 'an operation user',
            'ADMIN' => 'an administrator',
            default => 'somebody',
        };

        return 'Verified by ' . $who . ' on ' . $when . '.';
    }
}
