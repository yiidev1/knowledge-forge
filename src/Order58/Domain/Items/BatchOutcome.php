<?php

declare(strict_types=1);

namespace App\Order58\Domain\Items;

/**
 * What one synchronisation operation came to.
 *
 * ## Derived, never advanced optimistically
 *
 * These are computed from the batch's enrolment rows every time they are asked for — see
 * {@see self::from()}. There is deliberately no code path that sets `Completed` because something
 * finished; a batch is complete when it has nothing left unsettled, which is a question about rows
 * rather than a flag somebody remembered to flip.
 *
 * That matters most when a process dies mid-operation. A counter incremented after each order would be
 * wrong forever; a count of unsettled rows is simply re-read and is right again.
 */
enum BatchOutcome: string
{
    /** Still waiting on at least one order's items. */
    case Running = 'RUNNING';

    /** Every enrolled order settled, and none of them failed. */
    case Completed = 'COMPLETED';

    /** Every enrolled order settled, and at least one failed — or the scope was cut short. */
    case Partial = 'PARTIAL';

    /** The Orders Sync itself failed, so there was never a scope to work through. */
    case Failed = 'FAILED';

    /**
     * The verdict for one batch, from facts only.
     *
     * Named `decide` rather than `from` because a backed enum already has `from(string)`, and a factory
     * that shadowed it would be both illegal and confusing.
     *
     * `$truncated` is the Orders Sync having run out of time before covering its whole range. Those
     * orders were never enrolled, so no row is unsettled for them and the batch would otherwise read
     * `Completed` — which would be a lie about a scope nobody finished. It is reported `Partial`
     * instead, which is exactly what it is: everything asked for was done, but less was asked for than
     * intended.
     */
    public static function decide(int $unsettled, int $failed, bool $truncated, bool $ordersFailed): self
    {
        if ($ordersFailed) {
            return self::Failed;
        }

        if ($unsettled > 0) {
            return self::Running;
        }

        return $failed > 0 || $truncated ? self::Partial : self::Completed;
    }

    public function isFinished(): bool
    {
        return $this !== self::Running;
    }

    public function label(): string
    {
        return match ($this) {
            self::Running => 'In progress',
            self::Completed => 'Completed',
            self::Partial => 'Partially completed',
            self::Failed => 'Failed',
        };
    }

    /** CSS modifier, so the template holds no opinion about which outcome is which colour. */
    public function modifier(): string
    {
        return match ($this) {
            self::Running => 'info',
            self::Completed => 'ok',
            self::Partial => 'warning',
            self::Failed => 'error',
        };
    }
}
