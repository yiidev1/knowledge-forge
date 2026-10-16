<?php

declare(strict_types=1);

namespace App\OrderTesting\Web\Playback;

use App\OrderTesting\Domain\Playback\PlaybackRepositoryInterface;
use App\Shared\Audio\PlaybackGapOptions;
use App\Shared\Audio\RecordingIdentityReader;
use App\Shared\Audio\RecordingTypeLabels;
use App\Shared\Application\Time\AppTimeZone;
use DateTimeImmutable;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function array_map;
use function count;
use function explode;
use function is_string;
use function json_encode;
use function preg_match;

/**
 * What agents did to one order's recordings, counted — for whoever manages that order.
 *
 * ## One endpoint, two realms, no second implementation
 *
 * Mounted in the route group that admits administrators and operation users alike, which is the same
 * group the page itself is in. Neither realm gets a view of its own: they call this, and this reads
 * the one pair of tables. An agent cannot reach it — the agent group does not mount it.
 *
 * ## Read-only, and it owns nothing
 *
 * No write path exists here. The recordings it reports on are identified by the caller and verified
 * against the store in the route, so a recording id from another store reports nothing rather than
 * somebody else's listening.
 */
final readonly class SummaryAction
{
    public function __construct(
        private PlaybackRepositoryInterface $sessions,
        private RecordingIdentityReader $recordings,
        private AppTimeZone $zone,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        #[RouteArgument]
        int $sourceId,
    ): ResponseInterface {
        $jobs = $this->jobsInScope($request, $sourceId);

        if ($jobs === []) {
            return $this->json(['rows' => [], 'recordings' => []]);
        }

        $orderId = $jobs[0]->sourceOrderId;
        $ids = array_map(static fn($job): string => $job->jobPublicId, $jobs);

        $rows = array_map(
            fn($row): array => [
                'agentId' => $row->agentAdminId,
                'agent' => $row->agentDisplayName,
                'job' => $row->jobPublicId,
                'recording' => RecordingTypeLabels::shortForStorageValue($row->recordingLabel)
                    ?? 'Recording',
                // Each figure separately, because they answer different questions and the page must
                // not be able to add two of them together by accident.
                'requests' => $row->playRequests,
                'starts' => $row->playStarts,
                'completed' => $row->completed,
                'stopped' => $row->stopped,
                'errorsBeforeStart' => $row->errorsBeforeStart,
                'errorsAfterStart' => $row->errorsAfterStart,
                'selections' => $row->gapSelections,
                'lastPlayed' => $this->moment($row->lastStartedAt),
            ],
            $this->sessions->summaryFor($sourceId, $orderId, $ids),
        );

        return $this->json([
            'rows' => $rows,
            'recordings' => array_map(
                static fn($job): array => [
                    'job' => $job->jobPublicId,
                    'label' => RecordingTypeLabels::shortForStorageValue($job->recordingType)
                        ?? 'Recording',
                ],
                $jobs,
            ),
            'gaps' => PlaybackGapOptions::forBrowser(),
        ]);
    }

    /**
     * The recordings this request may ask about: the ones it named, kept only if they belong to the
     * store in the URL.
     *
     * The caller sends the job ids because the page already knows them — they are on the row it opened
     * the dialog from. Each is still re-read here and compared against the route's store, so a id
     * borrowed from another merchant's page reports nothing. An order id is never taken from the
     * request at all; it comes from the recording.
     *
     * @return list<\App\Shared\Audio\RecordingIdentity>
     */
    private function jobsInScope(ServerRequestInterface $request, int $sourceId): array
    {
        $raw = $request->getQueryParams()['jobs'] ?? '';

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $out = [];

        foreach (explode(',', $raw) as $candidate) {
            if (preg_match('/\A[0-9a-f]{32}\z/', $candidate) !== 1) {
                continue;
            }

            $recording = $this->recordings->find($candidate);

            if ($recording !== null && $recording->storeSourceId === $sourceId) {
                $out[] = $recording;
            }

            // Ten recordings is already more than any order here has; the cap is what stops a crafted
            // query from turning one dialog into a hundred lookups.
            if (count($out) >= 10) {
                break;
            }
        }

        return $out;
    }

    /**
     * A stored UTC instant as the reader's own clock shows it.
     *
     * Everything in this database is UTC; everything a person reads here is `APP_TIMEZONE`, with the
     * zone printed beside it. A timestamp with no zone on an analytics page is a timestamp somebody
     * will eventually misread by five hours.
     */
    private function moment(?string $utc): ?string
    {
        return $utc === null
            ? null
            : $this->zone->format(new DateTimeImmutable($utc . ' UTC'));
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = Status::OK): ResponseInterface
    {
        return $this->responses
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streams->createStream(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG),
            ));
    }
}
