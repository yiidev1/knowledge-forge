<?php

declare(strict_types=1);

namespace App\Order58\Web\RecentCalls;

use App\Integration\Order58Recording\CallSummary;
use App\Integration\Order58Recording\ChannelApiProbe;
use App\Integration\Order58Recording\ChannelDiagnosis;
use App\Integration\Order58Recording\FixtureRecordingSource;
use App\Integration\Order58Recording\LatestCallsRequest;
use DateTimeImmutable;
use Throwable;

use function array_keys;
use function array_map;
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
 * ## No database
 *
 * Nothing is read from or written to the database. Which receipts may be opened is remembered from this
 * very answer, by {@see ListAction} — see {@see RecentCallsReceipts}.
 */
final readonly class RecentCallsLoader
{
    public function __construct(
        private ChannelApiProbe $probe,
        private FixtureRecordingSource $fixtures,
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

        return new RecentCallsList(self::newestFirst($result->calls), null);
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
