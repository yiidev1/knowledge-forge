<?php

declare(strict_types=1);

namespace App\Order58\Web\RecentCalls;

use App\Integration\Order58Recording\CallSummary;
use App\Integration\Order58Recording\ChannelApiProbe;
use App\Integration\Order58Recording\ChannelDiagnosis;
use App\Integration\Order58Recording\FixtureRecordingSource;
use App\Integration\Order58Recording\LatestCallsRequest;
use App\Order58\Domain\Orders\Order58OrderRepositoryInterface;
use DateTimeImmutable;
use Throwable;

use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function preg_match;
use function usort;

/**
 * One store's latest calls, as the Recent Calls page lists them.
 *
 * ## One request, and no audio
 *
 * Exactly one call to the provider's `latest-calls` endpoint, through the shared {@see ChannelApiProbe}
 * — the same client, URL builder, timeouts and bounded JSON read the channel test page and the call
 * importer already use. No recording is requested here: audio is fetched only when somebody presses
 * Play or Download, one channel of one call at a time.
 *
 * ## One local read, and no writes
 *
 * Which of the listed orders this application has mirrored — a single indexed lookup for the whole
 * list — because the existing receipt link refuses an order it has not mirrored, and offering a link
 * that can only answer "not on this store" would be offering an error.
 */
final readonly class RecentCallsLoader
{
    public function __construct(
        private ChannelApiProbe $probe,
        private FixtureRecordingSource $fixtures,
        private Order58OrderRepositoryInterface $orders,
    ) {}

    public function load(int $accountId, bool $useFixtures): RecentCallsList
    {
        $request = new LatestCallsRequest($accountId, RecentCallsSettings::LIMIT);

        try {
            $result = $useFixtures ? $this->fixtures->latestCalls($request) : $this->probe->latestCalls($request);
        } catch (Throwable $e) {
            // Neither the URL nor the transport message is passed on: the message quotes the URL.
            return RecentCallsList::failed(ChannelDiagnosis::fromTransportFailure($e->getMessage()));
        }

        $diagnosis = $result->diagnosis;

        if ($diagnosis->kind === ChannelDiagnosis::NO_CALLS) {
            return RecentCallsList::empty();
        }

        // The fixture is labelled with a generic success diagnosis; its rows are what count.
        if (!$diagnosis->isSuccess() && !($useFixtures && $result->hasCalls())) {
            return RecentCallsList::failed($diagnosis);
        }

        $calls = self::newestFirst($result->calls);

        return new RecentCallsList($calls, null, $this->mirroredOrders($accountId, $calls));
    }

    /**
     * Newest call first.
     *
     * The provider's `callTime` format is documented nowhere, so the list is re-sorted only when every
     * value reads as a date and time; otherwise the provider's own order is kept rather than guessed at.
     * The sort is stable, so calls with the same time keep their relative order.
     *
     * @param list<CallSummary> $calls
     *
     * @return list<CallSummary>
     */
    public static function newestFirst(array $calls): array
    {
        $times = [];

        foreach ($calls as $index => $call) {
            $time = self::readTime($call->callTime);

            if ($time === null) {
                return $calls;
            }

            $times[$index] = $time;
        }

        $order = array_keys($calls);
        usort($order, static fn(int $a, int $b): int => $times[$b] <=> $times[$a]);

        return array_map(static fn(int $index): CallSummary => $calls[$index], $order);
    }

    /**
     * @param list<CallSummary> $calls
     *
     * @return array<int, true> the listed order ids this application has mirrored for the store
     */
    private function mirroredOrders(int $accountId, array $calls): array
    {
        $ids = [];

        foreach ($calls as $call) {
            if (preg_match('/\A\d{1,18}\z/', $call->orderId) === 1) {
                $ids[] = (int) $call->orderId;
            }
        }

        if ($ids === []) {
            return [];
        }

        $mirrored = [];

        foreach (array_keys($this->orders->hashesFor($accountId, array_values(array_unique($ids)))) as $orderId) {
            $mirrored[$orderId] = true;
        }

        return $mirrored;
    }

    private static function readTime(string $value): ?int
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?/', $value) !== 1) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
