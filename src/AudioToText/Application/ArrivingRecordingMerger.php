<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\GroupKey;
use App\AudioToText\Domain\StoreOrderGroup;
use App\Shared\Audio\PendingRecordingPortInterface;
use App\Shared\Audio\RecordingAcquisition;
use DateTimeImmutable;

use function array_key_exists;

/**
 * Puts the calls still being downloaded onto the store page, beside the ones that have arrived.
 *
 * ## The problem this solves
 *
 * The store page is built from stored conversations, and a conversation exists only once its audio has
 * landed. So between pressing Download and the first recording arriving — a minute or two, because the
 * fetch runs on a schedule — the page looked exactly as it had before. The work had started and the
 * screen said nothing, which reads as the button not having worked.
 *
 * ## Two kinds of merge, because there are two cases
 *
 * - **An order already on the page.** Its mixed recording arrived, the two sides are still coming. The
 *   group keeps everything it has and gains the acquisition, so its filled cells draw players and its
 *   empty ones say what is on the way.
 * - **An order not on the page at all.** Nothing of it has arrived. A group is made for it carrying no
 *   recordings — {@see StoreOrderGroup::isArrivingOnly()} — so the row appears immediately with its
 *   order id, its call time and three channels in progress.
 *
 * New rows go **first**. They are the most recent thing that happened and the reason the reader is
 * looking, and the page is ordered newest-first anyway.
 *
 * ## What it will not do
 *
 * It never invents a recording. An arriving group has no slots, so nothing offers to play, to open, or
 * to be transcribed — all of which are driven by slots that only exist once there is audio. That is the
 * whole safety property here, and it is structural rather than a rule anybody has to remember.
 */
final readonly class ArrivingRecordingMerger
{
    public function __construct(private PendingRecordingPortInterface $pending) {}

    /**
     * @param list<StoreOrderGroup> $groups the page of groups as stored conversations produced it
     *
     * @return list<StoreOrderGroup>
     */
    public function merge(int $storeSourceId, array $groups, DateTimeImmutable $now): array
    {
        $arriving = $this->pending->activeForStore($storeSourceId);

        if ($arriving === []) {
            return $groups;
        }

        /** @var array<string, RecordingAcquisition> $byOrder */
        $byOrder = [];
        /** @var list<RecordingAcquisition> $orderless */
        $orderless = [];

        foreach ($arriving as $acquisition) {
            if ($acquisition->orderId === null || $acquisition->orderId === '') {
                // A call the provider gave no order id for. It cannot be matched to a row, so it
                // becomes one of its own rather than being dropped.
                $orderless[] = $acquisition;

                continue;
            }

            // Two calls against one order is possible — somebody rang back. The first is the one shown,
            // because the page has one row per order and inventing a second would split the order.
            $byOrder[$acquisition->orderId] ??= $acquisition;
        }

        $merged = [];
        $matched = [];

        foreach ($groups as $group) {
            $orderId = $group->orderId;

            if ($orderId !== null && array_key_exists($orderId, $byOrder)) {
                $matched[$orderId] = true;
                $merged[] = $this->withArriving($group, $byOrder[$orderId]);

                continue;
            }

            $merged[] = $group;
        }

        $new = [];

        foreach ($byOrder as $acquisition) {
            // Read off the object rather than the array key: an order id is all digits, and PHP turns
            // an array key that looks like an integer into one. The property is the string it was.
            $orderId = (string) $acquisition->orderId;

            if (!array_key_exists($orderId, $matched)) {
                $new[] = $this->rowFor($acquisition, GroupKey::forOrder($orderId), $orderId, $now);
            }
        }

        foreach ($orderless as $acquisition) {
            // Keyed by the call rather than by an order, so two order-less calls do not collide. The key
            // is only ever used to open this row's dialogs, which an arriving row does not offer.
            $new[] = $this->rowFor(
                $acquisition,
                GroupKey::forOrder('call-' . $acquisition->callSessionId),
                null,
                $now,
            );
        }

        return [...$new, ...$merged];
    }

    /** The same group, now also saying what is on its way. */
    private function withArriving(StoreOrderGroup $group, RecordingAcquisition $acquisition): StoreOrderGroup
    {
        return new StoreOrderGroup(
            $group->key,
            $group->orderId,
            $group->latestActivityAt,
            $group->mixed,
            $group->caller,
            $group->callee,
            $group->legacySeparate,
            // The provider's own call time, preferred over whatever the stored conversations carried:
            // both come from the same place, and the import rows have it for a call whose conversations
            // do not exist yet.
            $group->callTimeRaw ?? $acquisition->callTimeRaw,
            $acquisition,
        );
    }

    /** A row for a call with nothing arrived yet: no slots, so nothing to play or transcribe. */
    private function rowFor(
        RecordingAcquisition $acquisition,
        GroupKey $key,
        ?string $orderId,
        DateTimeImmutable $now,
    ): StoreOrderGroup {
        return new StoreOrderGroup(
            $key,
            $orderId,
            // Nothing has happened to this call on this server yet, so "latest activity" is now — which
            // is also what keeps it sorted where the reader expects, at the top.
            $now,
            null,
            null,
            null,
            [],
            $acquisition->callTimeRaw,
            $acquisition,
        );
    }
}
