<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Order58;

use App\Order58\Domain\CallRecordings\CallRecordingRepositoryInterface;
use App\Order58\Domain\CallRecordings\NewRecording;
use App\Order58\Domain\CallRecordings\OrderCalls;
use App\Order58\Domain\CallRecordings\OrderRecordingChanges;
use App\Order58\Domain\CallRecordings\RecordingLocation;
use App\Order58\Domain\CallRecordings\StoredCallSession;
use App\Order58\Domain\CallRecordings\StoredOrderCalls;
use App\Order58\Domain\CallRecordings\StoredRecording;
use DateTimeImmutable;
use RuntimeException;

/**
 * The call recording tables, in memory, with the same identity rules as the real ones.
 *
 * Counts the writes it is asked for, so a test can prove that a repeated listing writes nothing.
 */
final class InMemoryCallRecordingRepository implements CallRecordingRepositoryInterface
{
    /** @var array<string, true> orders that "exist", keyed store:order */
    public array $orders = [];

    /** @var array<int, array{store: int, order: int, session: string}> */
    public array $sessions = [];

    /** @var array<int, array{session: int, position: int, url: string, hash: string, listed: bool}> */
    public array $recordings = [];

    public int $applyCalls = 0;

    /** Orders whose apply() throws, keyed store:order, to prove one failure does not stop the rest. */
    public array $failFor = [];

    private int $nextSession = 1;
    private int $nextRecording = 1;

    public function withOrder(int $store, int $order): self
    {
        $this->orders[OrderCalls::keyOf($store, $order)] = true;

        return $this;
    }

    public function storedOrders(array $orders): array
    {
        $stored = [];

        foreach ($orders as $order) {
            if (isset($this->orders[$order->key()])) {
                $stored[$order->key()] = true;
            }
        }

        return $stored;
    }

    public function sessionsFor(array $orders): array
    {
        $wanted = [];

        foreach ($orders as $order) {
            $wanted[$order->key()] = true;
        }

        $result = [];

        foreach ($this->sessions as $id => $session) {
            $key = OrderCalls::keyOf($session['store'], $session['order']);

            if (!isset($wanted[$key])) {
                continue;
            }

            $recordings = [];

            foreach ($this->recordings as $recordingId => $recording) {
                if ($recording['session'] === $id) {
                    $recordings[$recording['hash']] = new StoredRecording(
                        $recordingId,
                        $recording['position'],
                        $recording['listed'],
                    );
                }
            }

            $result[$key][$session['session']] = new StoredCallSession($id, $session['session'], $recordings);
        }

        return $result;
    }

    public function apply(int $storeSourceId, int $sourceOrderId, OrderRecordingChanges $changes, DateTimeImmutable $now): void
    {
        ++$this->applyCalls;

        if (isset($this->failFor[OrderCalls::keyOf($storeSourceId, $sourceOrderId)])) {
            throw new RuntimeException('simulated write failure');
        }

        foreach ($changes->newSessions as $sessionId => $recordings) {
            $id = $this->nextSession++;
            $this->sessions[$id] = ['store' => $storeSourceId, 'order' => $sourceOrderId, 'session' => (string) $sessionId];
            $this->add($id, $recordings);
        }

        foreach ($changes->newRecordings as $sessionRow => $recordings) {
            $this->add($sessionRow, $recordings);
        }

        foreach ($changes->updates as $recordingRow => $state) {
            $this->recordings[$recordingRow]['position'] = $state->position;
            $this->recordings[$recordingRow]['listed'] = $state->listed;
        }
    }

    public function locate(int $storeSourceId, int $sourceOrderId, int $recordingId): ?RecordingLocation
    {
        $recording = $this->recordings[$recordingId] ?? null;

        if ($recording === null || !$recording['listed']) {
            return null;
        }

        $session = $this->sessions[$recording['session']];

        if ($session['store'] !== $storeSourceId || $session['order'] !== $sourceOrderId) {
            return null;
        }

        return new RecordingLocation(
            $recordingId,
            $storeSourceId,
            $sourceOrderId,
            $session['session'],
            $recording['position'],
            $recording['url'],
        );
    }

    /** @return list<StoredOrderCalls> */
    public function storedCallsPage(int $afterRowId, int $limit, ?int $storeSourceId = null): array
    {
        return [];
    }

    /** The current links of one order, in display order: session, then position. */
    public function urlsOf(int $store, int $order): array
    {
        $urls = [];

        foreach ($this->sessions as $id => $session) {
            if ($session['store'] !== $store || $session['order'] !== $order) {
                continue;
            }

            $byPosition = [];

            foreach ($this->recordings as $recording) {
                if ($recording['session'] === $id && $recording['listed']) {
                    $byPosition[$recording['position']] = $recording['url'];
                }
            }

            ksort($byPosition);

            foreach ($byPosition as $url) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /** @param list<NewRecording> $recordings */
    private function add(int $sessionRow, array $recordings): void
    {
        foreach ($recordings as $recording) {
            foreach ($this->recordings as $existing) {
                if ($existing['session'] === $sessionRow && $existing['hash'] === $recording->urlHash) {
                    throw new RuntimeException('duplicate recording — the unique key would refuse this');
                }
            }

            $this->recordings[$this->nextRecording++] = [
                'session' => $sessionRow,
                'position' => $recording->position,
                'url' => $recording->url,
                'hash' => $recording->urlHash,
                'listed' => true,
            ];
        }
    }
}
