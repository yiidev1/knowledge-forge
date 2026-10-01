<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Domain\CallImportOutcome;
use App\Order58\Domain\Order58ImportStatus;
use App\Order58\Domain\RecordingDownloadState;
use PHPUnit\Framework\TestCase;

/**
 * The word the recordings page puts on one call.
 *
 * This enum exists for a single disagreement with {@see CallImportOutcome}, and that disagreement is
 * what most of this file is about: a merchant who records the mixed call and not the two sides produces
 * a 404 on CALLER and CALLEE **every single time**, and that is a healthy download, not a failure.
 */
final class RecordingDownloadStateTest extends TestCase
{
    /** No rows at all is the state the button acts from. */
    public function testACallNobodyHasAskedForIsNotDownloaded(): void
    {
        self::assertSame(RecordingDownloadState::NotDownloaded, RecordingDownloadState::fromChannels(null));
        self::assertSame(RecordingDownloadState::NotDownloaded, RecordingDownloadState::fromChannels([]));
    }

    /** Null and the empty list are the same thing: a call is not stored until its channels are. */
    public function testNullAndEmptyAgree(): void
    {
        self::assertSame(
            RecordingDownloadState::fromChannels(null),
            RecordingDownloadState::fromChannels([]),
        );
    }

    public function testEveryChannelHereIsDownloaded(): void
    {
        self::assertSame(
            RecordingDownloadState::Downloaded,
            RecordingDownloadState::fromChannels([
                Order58ImportStatus::Imported,
                Order58ImportStatus::Imported,
                Order58ImportStatus::Imported,
            ]),
        );
    }

    /**
     * Anything still moving outranks every settled answer.
     *
     * A final word about a call whose caller channel is still being fetched would be wrong before it was
     * printed — and "Partial" is exactly the wrong word, because the missing side may be seconds away.
     */
    public function testOneChannelStillMovingMakesTheWholeCallDownloading(): void
    {
        foreach ([Order58ImportStatus::Pending, Order58ImportStatus::Fetching] as $moving) {
            self::assertSame(
                RecordingDownloadState::Downloading,
                RecordingDownloadState::fromChannels([
                    Order58ImportStatus::Imported,
                    Order58ImportStatus::NotAvailable,
                    $moving,
                ]),
                'A settled majority does not settle the call.',
            );
        }
    }

    /**
     * **The reason this enum exists.**
     *
     * All three channels settled, none of them here, and nothing went wrong: the provider simply has no
     * such recordings. Reporting that as a failure would paint the column red on an ordinary day, and a
     * column that is red on ordinary days is a column nobody reads by the second week.
     */
    public function testACallTheProviderSimplyDoesNotHaveIsUnavailableRatherThanFailed(): void
    {
        $state = RecordingDownloadState::fromChannels([
            Order58ImportStatus::NotAvailable,
            Order58ImportStatus::NotAvailable,
            Order58ImportStatus::NotAvailable,
        ]);

        self::assertSame(RecordingDownloadState::Unavailable, $state);
        self::assertSame('Unavailable', $state->label());

        // And this is precisely where the two readings part company. The calls page is right to call it
        // a failure — it wanted a transcript and has nothing to transcribe. This page wanted audio and
        // has learned there is none, which is an answer.
        self::assertSame(
            CallImportOutcome::Failed,
            CallImportOutcome::fromChannels([
                Order58ImportStatus::NotAvailable,
                Order58ImportStatus::NotAvailable,
                Order58ImportStatus::NotAvailable,
            ]),
            'The calls page must keep reading this exactly as it always has.',
        );
    }

    /** The ordinary single-channel merchant: one recording here, the two sides never offered. */
    public function testTheTypicalMerchantReadsAsPartial(): void
    {
        self::assertSame(
            RecordingDownloadState::Partial,
            RecordingDownloadState::fromChannels([
                Order58ImportStatus::Imported,
                Order58ImportStatus::NotAvailable,
                Order58ImportStatus::NotAvailable,
            ]),
        );
    }

    /** A real failure is still a failure, and is distinguished from an absence. */
    public function testSomethingThatWentWrongIsFailed(): void
    {
        self::assertSame(
            RecordingDownloadState::Failed,
            RecordingDownloadState::fromChannels([
                Order58ImportStatus::Failed,
                Order58ImportStatus::NotAvailable,
                Order58ImportStatus::NotAvailable,
            ]),
            'One genuine failure among absences is not an absence.',
        );

        self::assertSame(
            RecordingDownloadState::Failed,
            RecordingDownloadState::fromChannels([Order58ImportStatus::TooLarge]),
            'A refusal on size is this server\'s doing, and worth looking at.',
        );
    }

    /** Asking again is offered only where it could bring down something new. */
    public function testAskingAgainIsOfferedOnlyWhereItCouldHelp(): void
    {
        self::assertTrue(RecordingDownloadState::NotDownloaded->worthAsking());
        self::assertTrue(RecordingDownloadState::Partial->worthAsking());
        self::assertTrue(RecordingDownloadState::Failed->worthAsking());

        // The provider has answered. Asking a second time spends a request to be told the same thing.
        self::assertFalse(RecordingDownloadState::Unavailable->worthAsking());
        self::assertFalse(RecordingDownloadState::Downloading->worthAsking());
        self::assertFalse(RecordingDownloadState::Downloaded->worthAsking());
    }

    /** Six states, six words, and every one of them is something an operator would say. */
    public function testEveryStateReadsAsPlainEnglish(): void
    {
        $expected = [
            'NOT_DOWNLOADED' => 'Not downloaded',
            'DOWNLOADING' => 'Downloading',
            'DOWNLOADED' => 'Downloaded',
            'PARTIAL' => 'Partial',
            'UNAVAILABLE' => 'Unavailable',
            'FAILED' => 'Failed',
        ];

        foreach (RecordingDownloadState::cases() as $case) {
            self::assertArrayHasKey($case->value, $expected, 'A new state needs a word of its own.');
            self::assertSame($expected[$case->value], $case->label());
        }

        self::assertCount(6, RecordingDownloadState::cases());
    }

    /** And every badge is a class `admin.css` actually defines. */
    public function testEveryBadgeIsOneThePaletteHas(): void
    {
        foreach (RecordingDownloadState::cases() as $case) {
            self::assertContains(
                $case->badge(),
                ['success', 'error', 'warning', 'info', 'muted'],
                $case->name . ' asks for a badge style that does not exist.',
            );
        }
    }
}
