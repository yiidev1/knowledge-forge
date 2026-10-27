<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\ArrivingRecordingMerger;
use App\AudioToText\Domain\GroupKey;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\StoreOrderGroup;
use App\AudioToText\Domain\StoreRecordingSlot;
use App\AudioToText\Domain\TranscriptionProvider;
use App\Shared\Audio\PendingRecordingPortInterface;
use App\Shared\Audio\RecordingAcquisition;
use App\Shared\Audio\RecordingAcquisitionState;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;

use function array_map;

/**
 * Where a call that is still downloading appears on the store page.
 *
 * ## Why this is worth its own test now
 *
 * This merger used to answer one question — "is this order on the page?" — and the answer was always
 * no for an order that had only just been asked for, because the page was built from stored recordings
 * and that order had none. So it made a row and put it first, and pressing Download visibly did
 * something.
 *
 * The listing now includes a store's orders whether or not anything was recorded against them, which
 * means the order usually *is* on the page already. Left alone, the merger would annotate it where it
 * sits — several pages down for a store with a few hundred orders — and pressing Download would again
 * look like nothing had happened. The cases below pin the behaviour on both sides of that change.
 */
final class ArrivingRecordingMergerTest extends Unit
{
    private const STORE = 987654901;

    /** A row with nothing recorded against it: an order the listing now carries on its own. */
    public function testAnOrderWithNoRecordingsIsHoistedToTheTopWhenItsAudioIsArriving(): void
    {
        $merged = $this->merge(
            [$this->orderRow('500000001'), $this->orderRow('500000002'), $this->orderRow('500000003')],
            [$this->arriving('500000003')],
        );

        self::assertSame(
            ['order:500000003', 'order:500000001', 'order:500000002'],
            $this->keys($merged),
            'the call being downloaded leads the page',
        );

        self::assertNotNull($merged[0]->arriving, 'and it carries what is on the way');
        self::assertTrue($merged[0]->isArrivingOnly());
    }

    /**
     * An order that already has a recording stays exactly where it was.
     *
     * The hoist is for a row that is *only* what is arriving. A call whose mixed recording is in hand
     * and whose other sides are still coming is an ordinary row of the page with a note on it, and
     * moving it would reorder a page an administrator is reading.
     */
    public function testAnOrderThatAlreadyHasARecordingIsNotMoved(): void
    {
        $withRecording = $this->recordedRow('500000011');

        $merged = $this->merge(
            [$this->orderRow('500000010'), $withRecording, $this->orderRow('500000012')],
            [$this->arriving('500000011')],
        );

        self::assertSame(
            ['order:500000010', 'order:500000011', 'order:500000012'],
            $this->keys($merged),
        );

        self::assertNotNull($merged[1]->arriving, 'it still gained the acquisition');
    }

    /** An order the page does not carry at all still gets a row of its own, first. */
    public function testAnOrderNotOnThePageStillBecomesItsOwnRow(): void
    {
        $merged = $this->merge([$this->orderRow('500000020')], [$this->arriving('500000021')]);

        self::assertSame(['order:500000021', 'order:500000020'], $this->keys($merged));
        self::assertTrue($merged[0]->isArrivingOnly());
    }

    /** And it is not added twice when the page already carries it. */
    public function testAnArrivingOrderAlreadyOnThePageIsNotDuplicated(): void
    {
        $merged = $this->merge([$this->orderRow('500000030')], [$this->arriving('500000030')]);

        self::assertCount(1, $merged);
        self::assertSame(['order:500000030'], $this->keys($merged));
    }

    /** Nothing arriving changes nothing, which is the usual case and has to stay free. */
    public function testNothingArrivingLeavesThePageExactlyAsItWas(): void
    {
        $groups = [$this->orderRow('500000040'), $this->orderRow('500000041')];

        self::assertSame($groups, $this->merge($groups, []));
    }

    // ----------------------------------------------------------------------------------- fixtures

    /**
     * @param list<StoreOrderGroup>      $groups
     * @param list<RecordingAcquisition> $arriving
     *
     * @return list<StoreOrderGroup>
     */
    private function merge(array $groups, array $arriving): array
    {
        $merger = new ArrivingRecordingMerger(new class ($arriving) implements PendingRecordingPortInterface {
            /** @param list<RecordingAcquisition> $arriving */
            public function __construct(private array $arriving) {}

            public function activeForStore(int $storeSourceId): array
            {
                return $this->arriving;
            }
        });

        return $merger->merge(self::STORE, $groups, $this->now());
    }

    /** An order with nothing recorded against it — what the listing's second tier produces. */
    private function orderRow(string $orderId): StoreOrderGroup
    {
        return new StoreOrderGroup(GroupKey::forOrder($orderId), $orderId, $this->now());
    }

    /**
     * An order that already has audio.
     *
     * Only `isArrivingOnly()` is being exercised, and it answers on `primaries()` — so a legacy pair
     * standing in for "this row has a recording" is enough, and it avoids building a slot whose
     * fields this test says nothing about.
     */
    private function recordedRow(string $orderId): StoreOrderGroup
    {
        return new StoreOrderGroup(
            GroupKey::forOrder($orderId),
            $orderId,
            $this->now(),
            null,
            null,
            null,
            [$this->slot()],
        );
    }

    private function slot(): StoreRecordingSlot
    {
        // Only `primaries()` being non-empty matters here: that is what `isArrivingOnly()` answers on.
        // A complete, playable recording is the clearest stand-in for "this row has audio".
        return new StoreRecordingSlot(
            conversationPublicId: 'conv-' . self::STORE,
            jobPublicId: 'job-' . self::STORE,
            recordingType: RecordingType::Mixed,
            sourceRole: SourceRole::Common,
            status: JobStatus::COMPLETED,
            provider: TranscriptionProvider::Whisper,
            durationSeconds: 12.0,
            uploadedAt: $this->now(),
            originalFilename: 'mix.wav',
            hasOriginalAudio: true,
            hasTranscript: true,
            hasSegments: true,
            rolesConfirmed: true,
        );
    }

    private function arriving(string $orderId): RecordingAcquisition
    {
        return new RecordingAcquisition(
            callSessionId: 'call-' . $orderId,
            orderId: $orderId,
            callTimeRaw: '2026-10-26 09:00:00',
            channels: ['mixed' => RecordingAcquisitionState::Downloading],
        );
    }

    /** @param list<StoreOrderGroup> $groups @return list<string> */
    private function keys(array $groups): array
    {
        return array_map(static fn(StoreOrderGroup $group): string => $group->key->value, $groups);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-26 09:00:00', new DateTimeZone('UTC'));
    }
}
