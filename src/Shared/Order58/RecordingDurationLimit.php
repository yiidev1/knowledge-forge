<?php

declare(strict_types=1);

namespace App\Shared\Order58;

use Yiisoft\Db\Expression\Expression;

use function is_finite;
use function is_numeric;
use function preg_match;
use function rtrim;
use function sprintf;
use function trim;

/**
 * The longest a Case Order call recording may be for its order to be offered, and the one place that
 * says so.
 *
 * ## The rule
 *
 * `allowed seconds = order58_orders.total_amount × MULTIPLIER ÷ DIVISOR × 60` — with the client's
 * current numbers, a $84.48 order allows 84.48 × 2 ÷ 10 = 16.896 minutes = 1013.76 seconds. A recording
 * longer than its order's limit is excluded; one exactly at the limit is kept. An order whose every
 * current recording is excluded is hidden; an order with at least one recording that is not excluded —
 * or with no recording at all — stays.
 *
 * ## Conservative wherever a fact is missing
 *
 * A recording is excluded only when its duration is **known** (stored, see `order58_call_recordings.
 * duration_seconds`) **and** its order has a positive total. A duration that has not been measured yet
 * — the normal state before the backfill reaches it, and always so on a machine the recording service
 * does not allowlist — never hides anything; nor does a NULL, zero, negative or unreadable total. When
 * the rule is off, nothing is excluded at all.
 *
 * ## One formula, two languages
 *
 * Pages are filtered in SQL, so paging and counts stay right; a test or a single row is judged in PHP.
 * Both are produced here, from the same two numbers, and the SQL compares without dividing —
 * `duration × DIVISOR > total × MULTIPLIER × 60` — so MySQL's exact DECIMAL arithmetic decides the
 * boundary and nothing is rounded before the comparison. Changing the formula is changing the two
 * settings (`RECORDING_DURATION_MULTIPLIER`, `RECORDING_DURATION_DIVISOR`); no stored duration needs to
 * be measured again, because what is stored is the recording's length, not a verdict.
 */
final readonly class RecordingDurationLimit
{
    /** Bounds the settings are held to; out of range, the rule turns itself off rather than misjudge. */
    private const MAX_FACTOR = 1_000_000.0;

    private bool $active;

    public function __construct(
        bool $enabled = false,
        public float $multiplier = 2.0,
        public float $divisor = 10.0,
    ) {
        // A divisor of zero, a negative or a non-finite factor would make every limit meaningless.
        // The environment already refuses such values at startup; this is the second line, for any
        // other way the class gets built.
        $this->active = $enabled && self::isFactor($multiplier) && self::isFactor($divisor);
    }

    public function isEnabled(): bool
    {
        return $this->active;
    }

    /**
     * The allowed length for an order of this total, in seconds — or null when no limit applies:
     * the rule is off, or the total is missing, not a number, zero or negative.
     */
    public function allowedSeconds(?string $totalAmount): ?float
    {
        $total = $this->positiveTotal($totalAmount);

        return $total === null ? null : $total * $this->multiplier / $this->divisor * 60.0;
    }

    /** Whether a recording this long may be offered for an order of this total. Unknown length: yes. */
    public function allowsRecording(?float $durationSeconds, ?string $totalAmount): bool
    {
        $total = $this->positiveTotal($totalAmount);

        if ($total === null || $durationSeconds === null) {
            return true;
        }

        // Multiplied out rather than divided, exactly as the SQL below does, so the two agree.
        return $durationSeconds * $this->divisor <= $total * $this->multiplier * 60.0;
    }

    /**
     * Whether an order with these recordings stays listed: unless it has recordings and every one of
     * them is excluded.
     *
     * @param list<float|null> $durationsSeconds the order's current recordings; null = not measured
     */
    public function keepsOrder(array $durationsSeconds, ?string $totalAmount): bool
    {
        if ($durationsSeconds === []) {
            return true;
        }

        foreach ($durationsSeconds as $duration) {
            if ($this->allowsRecording($duration, $totalAmount)) {
                return true;
            }
        }

        return false;
    }

    /**
     * SQL: recording `$r` is NOT excluded for order `$o` — null when the rule is off (nothing to add).
     *
     * `$r` must be an alias of `order58_call_recordings` and `$o` of `order58_orders` for the same order.
     */
    public function recordingAllowedSql(string $r, string $o): ?Expression
    {
        return $this->active ? new Expression('NOT (' . $this->overSql($r, $o) . ')') : null;
    }

    /**
     * SQL: order `$o` (an alias of `order58_orders`) stays listed — null when the rule is off.
     *
     * Listed unless it has a current recording over its limit and no current recording that is not.
     * Correlated on the sessions' unique key and the recordings' session prefix, so each order costs
     * two index probes.
     */
    public function orderVisibleSql(string $o): ?Expression
    {
        if (!$this->active) {
            return null;
        }

        $recordingsOf = 'SELECT 1 FROM {{%order58_call_sessions}} kf_rd_s'
            . ' INNER JOIN {{%order58_call_recordings}} kf_rd_r'
            . ' ON kf_rd_r.session_id = kf_rd_s.id AND kf_rd_r.delisted_at IS NULL'
            . ' WHERE kf_rd_s.store_source_id = ' . $o . '.account_id'
            . ' AND kf_rd_s.source_order_id = ' . $o . '.source_order_id';
        $over = $this->overSql('kf_rd_r', $o);

        return new Expression(
            '(NOT EXISTS (' . $recordingsOf . ' AND ' . $over . ')'
            . ' OR EXISTS (' . $recordingsOf . ' AND NOT (' . $over . ')))',
        );
    }

    /**
     * The one SQL statement of the formula: known duration, positive total, and longer than allowed.
     *
     * Wrapped in `IS TRUE` so it is always TRUE or FALSE, never NULL: a NULL total makes the comparison
     * NULL, and `NOT (NULL)` is still NULL — which a WHERE clause treats as false, silently dropping a
     * recording the rule meant to keep.
     *
     * The factors are written as decimal literals, which MySQL keeps exact. They come from
     * {@see isFactor()}-checked floats and {@see literal()} prints nothing but digits and a point, so
     * nothing outside this class reaches the SQL text.
     */
    private function overSql(string $r, string $o): string
    {
        return '((' . $r . '.duration_seconds IS NOT NULL AND ' . $o . '.total_amount > 0'
            . ' AND ' . $r . '.duration_seconds * ' . self::literal($this->divisor)
            . ' > ' . $o . '.total_amount * ' . self::literal($this->multiplier) . ' * 60) IS TRUE)';
    }

    private function positiveTotal(?string $totalAmount): ?float
    {
        if (!$this->active || $totalAmount === null || !is_numeric(trim($totalAmount))) {
            return null;
        }

        $total = (float) trim($totalAmount);

        return $total > 0.0 && is_finite($total) ? $total : null;
    }

    private static function isFactor(float $value): bool
    {
        return is_finite($value) && $value > 0.0 && $value <= self::MAX_FACTOR;
    }

    /** `2.0` → `2`, `0.125` → `0.125`: a plain decimal literal, digits and at most one point. */
    private static function literal(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

        return preg_match('/\A[0-9]+(\.[0-9]+)?\z/', $text) === 1 ? $text : '1';
    }
}
