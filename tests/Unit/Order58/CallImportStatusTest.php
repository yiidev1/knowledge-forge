<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Integration\Order58Recording\RecordingChannel;
use App\Order58\Domain\CallImportHistoryRow;
use App\Order58\Domain\CallImportItem;
use App\Order58\Domain\CallImportOutcome;
use App\Order58\Domain\Order58ImportStatus;
use Codeception\Test\Unit;
use DateTimeImmutable;

use function array_map;

/**
 * What a call's status says, given what its channels did.
 *
 * The rule exists because a call is not a row: it is up to three independent imports, and the common,
 * healthy case is that two of them do not exist. A merchant without separated channels produces a mixed
 * recording and two 404s, and if that read as "Failed" — or even as an error colour — the column would be
 * noise from the first day and nobody would look at it again.
 *
 * So these tests are mostly about the *non*-failures: a missing channel is a fact about the provider, an
 * oversized one is a decision this application has not been given, and neither is a reason to retry or to
 * alarm anybody.
 */
final class CallImportStatusTest extends Unit
{
    // ------------------------------------------------------------------ the call-level rule

    /**
     * @dataProvider channelCombinations
     *
     * @param list<Order58ImportStatus> $channels
     */
    public function testACallsOutcomeIsDerivedFromItsChannels(
        array $channels,
        CallImportOutcome $expected,
        string $why,
    ): void {
        self::assertSame($expected, CallImportOutcome::fromChannels($channels), $why);
    }

    /**
     * @return iterable<string, array{list<Order58ImportStatus>, CallImportOutcome, string}>
     */
    public function channelCombinations(): iterable
    {
        $imported = Order58ImportStatus::Imported;
        $missing = Order58ImportStatus::NotAvailable;
        $failed = Order58ImportStatus::Failed;
        $big = Order58ImportStatus::TooLarge;
        $pending = Order58ImportStatus::Pending;
        $fetching = Order58ImportStatus::Fetching;

        yield 'all three imported' => [
            [$imported, $imported, $imported],
            CallImportOutcome::Completed,
            'Everything asked for arrived.',
        ];

        // The ordinary case for most merchants, and the reason Partial must not look like damage.
        yield 'mixed only, separated channels absent' => [
            [$imported, $missing, $missing],
            CallImportOutcome::Partial,
            'A merchant without separated channels is a normal, successful import.',
        ];

        yield 'one side missing' => [
            [$imported, $imported, $missing],
            CallImportOutcome::Partial,
            'One side absent still leaves a usable call.',
        ];

        yield 'mixed imported, one channel too large' => [
            [$imported, $big, $missing],
            CallImportOutcome::Partial,
            'A refused oversize channel does not undo the one that worked.',
        ];

        yield 'mixed imported, one channel failed' => [
            [$imported, $failed, $missing],
            CallImportOutcome::Partial,
            'A failure alongside a success is partial, not failed.',
        ];

        yield 'nothing arrived' => [
            [$failed, $failed, $failed],
            CallImportOutcome::Failed,
            'No recording at all is the only outright failure.',
        ];

        yield 'every channel absent' => [
            [$missing, $missing, $missing],
            CallImportOutcome::Failed,
            'A call with no mixed recording either produced nothing usable.',
        ];

        yield 'still queued' => [
            [$pending, $pending, $pending],
            CallImportOutcome::Processing,
            'Nothing has been attempted yet.',
        ];

        // The ordering rule: unfinished work outranks a settled shortfall, because the answer is not
        // final. Reporting "Partial" here would be wrong the moment the caller channel arrives.
        yield 'one imported, one missing, one still fetching' => [
            [$imported, $missing, $fetching],
            CallImportOutcome::Processing,
            'An unfinished channel means the outcome is not decided yet.',
        ];

        yield 'one failed, one still pending' => [
            [$failed, $pending, $missing],
            CallImportOutcome::Processing,
            'A pending channel could still rescue the call.',
        ];
    }

    // ------------------------------------------------------------------ what each channel state means

    /** Only an outright failure may be retried; the other settled states are answers, not errors. */
    public function testOnlyAFailureIsOfferedForRetry(): void
    {
        self::assertTrue(Order58ImportStatus::Failed->isRetryable());

        foreach ([
            Order58ImportStatus::Imported,
            Order58ImportStatus::NotAvailable,
            Order58ImportStatus::TooLarge,
            Order58ImportStatus::Pending,
            Order58ImportStatus::Fetching,
        ] as $status) {
            self::assertFalse(
                $status->isRetryable(),
                $status->value . ' must not offer a retry: it is not a failure retrying could fix.',
            );
        }
    }

    /** The worker is finished with every state but the two that mean work outstanding. */
    public function testOnlyPendingAndFetchingAreUnsettled(): void
    {
        self::assertFalse(Order58ImportStatus::Pending->isSettled());
        self::assertFalse(Order58ImportStatus::Fetching->isSettled());

        foreach ([
            Order58ImportStatus::Imported,
            Order58ImportStatus::NotAvailable,
            Order58ImportStatus::TooLarge,
            Order58ImportStatus::Failed,
        ] as $status) {
            self::assertTrue($status->isSettled(), $status->value . ' is a terminal state.');
        }
    }

    /**
     * Every badge names a class the stylesheet actually defines.
     *
     * `admin.css` carries exactly five `.badge--*` rules. `SyncRunStatus` returns names outside that set
     * and its badges render unstyled on the Data Management page — a mistake worth not repeating, and
     * one a test catches for free.
     */
    public function testEveryBadgeUsesAPaletteThatExists(): void
    {
        $palette = ['success', 'error', 'warning', 'info', 'muted'];

        foreach (Order58ImportStatus::cases() as $status) {
            self::assertContains($status->badge(), $palette, $status->value . ' has no such badge style.');
            self::assertNotSame('', $status->label());
        }

        foreach (CallImportOutcome::cases() as $outcome) {
            self::assertContains($outcome->badge(), $palette, $outcome->value . ' has no such badge style.');
            self::assertNotSame('', $outcome->label());
        }
    }

    // ------------------------------------------------------------------ the history row

    /**
     * A call that was only ever asked for one channel is complete, not partial.
     *
     * "Absent from the table" and "asked for and refused" are different things, and only the second is a
     * shortfall. This matters for anything queued before a merchant's separated channels were known.
     */
    public function testAChannelNeverRequestedIsNotCountedAsAShortfall(): void
    {
        $row = $this->row([RecordingChannel::Mixed->value => Order58ImportStatus::Imported]);

        self::assertSame(CallImportOutcome::Completed, $row->outcome());
        self::assertNull($row->channel(RecordingChannel::Caller), 'Never requested, so there is no cell.');
        self::assertFalse($row->hasRetryable());
    }

    public function testARowOffersRetryOnlyWhenAChannelFailed(): void
    {
        self::assertTrue($this->row([
            RecordingChannel::Mixed->value => Order58ImportStatus::Imported,
            RecordingChannel::Caller->value => Order58ImportStatus::Failed,
        ])->hasRetryable());

        self::assertFalse($this->row([
            RecordingChannel::Mixed->value => Order58ImportStatus::Imported,
            RecordingChannel::Caller->value => Order58ImportStatus::NotAvailable,
        ])->hasRetryable(), 'A merchant without this channel is nothing to retry.');
    }

    /**
     * @param array<string, Order58ImportStatus> $channels
     */
    private function row(array $channels): CallImportHistoryRow
    {
        $at = new DateTimeImmutable('2026-09-24 06:09:16');

        return new CallImportHistoryRow(
            1491,
            '888 Chinese',
            '22487129',
            '16547451',
            '2026-09-24 06:09:16',
            array_map(
                fn(Order58ImportStatus $status): CallImportItem => new CallImportItem(
                    1,
                    1,
                    1491,
                    '22487129',
                    RecordingChannel::Mixed,
                    '2026-09-24 06:09:16',
                    '2026-09-24',
                    '16547451',
                    $status,
                    0,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    $at,
                    'WGEU',
                    'WHISPER',
                    false,
                    1,
                ),
                $channels,
            ),
            'WHISPER',
            false,
            $at,
        );
    }
}
