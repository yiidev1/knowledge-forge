<?php

declare(strict_types=1);

namespace App\OrderTesting\Web\Playback;

use App\OrderTesting\Domain\Playback\PlaybackRepositoryInterface;
use App\OrderTesting\Domain\Playback\PlaybackSession;
use App\Shared\Application\Time\AppTimeZone;
use App\Shared\Audio\PlaybackGapOptions;
use App\Shared\Audio\RecordingIdentityReader;
use App\Shared\Audio\RecordingTypeLabels;
use DateTimeImmutable;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function array_map;
use function ceil;
use function count;
use function explode;
use function is_string;
use function json_encode;
use function max;
use function min;
use function preg_match;

/**
 * One agent's playback attempts on one order, a page at a time — plus the selections that were not
 * attempts at all.
 *
 * ## Why the selections travel with the history
 *
 * "Which gaps did this agent try?" and "what did this agent play?" are the same enquiry to the person
 * asking, and two queries to the database. They arrive together and are kept apart on screen: a
 * selection has no status and no progress, and the modal shows it in its own section so it can never
 * be read as an attempt.
 */
final readonly class HistoryAction
{
    /** One page of attempts. Enough that most orders need no second page; small enough to stay fast. */
    private const PER_PAGE = 25;

    /** Selections are context, not a log to page through. */
    private const SELECTION_LIMIT = 50;

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
        #[RouteArgument]
        int $agentId,
    ): ResponseInterface {
        $query = $request->getQueryParams();
        $jobs = $this->jobsInScope($query['jobs'] ?? '', $sourceId);

        if ($jobs === [] || $agentId <= 0) {
            return $this->json(['rows' => [], 'selections' => [], 'total' => 0, 'page' => 1, 'pages' => 1]);
        }

        $orderId = $jobs[0]->sourceOrderId;
        $ids = array_map(static fn($job): string => $job->jobPublicId, $jobs);

        $total = $this->sessions->countHistoryFor($sourceId, $orderId, $ids, $agentId);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($query['page'] ?? 1)));

        $rows = array_map(
            fn(PlaybackSession $session): array => [
                'requestedAt' => $this->moment($session->requestedAt),
                'startedAt' => $this->moment($session->startedAt),
                'finishedAt' => $this->moment($session->finishedAt),
                'gap' => PlaybackGapOptions::label($session->gapMs),
                'status' => $session->status->label(),
                'statusCode' => $session->status->value,
                // True of the row, not of the status: a session that began and was then stopped still
                // began, and this is the field the counting uses.
                'started' => $session->hasStarted(),
                'recording' => RecordingTypeLabels::shortForStorageValue($session->recordingLabel)
                    ?? 'Recording',
                'progress' => $session->messageTotal > 0
                    ? $session->messagePlayed . '/' . $session->messageTotal
                    : null,
            ],
            $this->sessions->historyFor(
                $sourceId,
                $orderId,
                $ids,
                $agentId,
                self::PER_PAGE,
                ($page - 1) * self::PER_PAGE,
            ),
        );

        $selections = array_map(
            fn($row): array => [
                'gap' => PlaybackGapOptions::label($row->gapMs),
                'recording' => RecordingTypeLabels::shortForStorageValue($row->recordingLabel)
                    ?? 'Recording',
                'at' => $this->moment($row->selectedAt),
            ],
            $this->sessions->selectionsFor($sourceId, $orderId, $ids, $agentId, self::SELECTION_LIMIT),
        );

        return $this->json([
            'rows' => $rows,
            'selections' => $selections,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /**
     * The recordings this request may ask about — re-read and confined to the route's store.
     *
     * @return list<\App\Shared\Audio\RecordingIdentity>
     */
    private function jobsInScope(mixed $raw, int $sourceId): array
    {
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

            if (count($out) >= 10) {
                break;
            }
        }

        return $out;
    }

    private function moment(?string $utc): ?string
    {
        return $utc === null ? null : $this->zone->format(new DateTimeImmutable($utc . ' UTC'));
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
