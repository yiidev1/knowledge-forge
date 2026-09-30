<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Status;

use App\AudioToText\Domain\ProcessingEstimate;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Web\Job\JobPageGuard;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function json_encode;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * The polling endpoint — the smallest useful response.
 *
 * Three enum values and a rough estimate. No transcript (the page fetches that once, by reloading,
 * rather than re-sending it every two seconds), no filesystem path, no stderr, no process id, no job id,
 * no uploader. The prose for each value lives in the client-side label map, so this endpoint publishes
 * only keys and cannot leak a message that was written for a log.
 *
 * `eta` is two second counts or null, and is the one thing here that is not a fact: see
 * {@see ProcessingEstimate} for why it is a range, why the queue wait is inside it, and why nothing
 * counts down. Computed on this side so the ratios behind it live in one place rather than in
 * JavaScript, and null whenever the recording does not support an honest one — the screen then says
 * "This may take a few minutes" and shows elapsed time instead.
 */
final readonly class Action
{
    public function __construct(
        private TranscriptionJobRepositoryInterface $jobs,
        private JobPageGuard $guard,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId): ResponseInterface
    {
        $job = $this->jobs->findByPublicId($publicId);

        if ($job === null) {
            return $this->guard->notFound();
        }

        $body = json_encode([
            'status' => $job->status->value,
            'stage' => $job->stage?->value,
            'speakerSeparation' => $job->speakerSeparationStatus?->value,
            // Approximate, bounded, and never a countdown. Null where this recording cannot support an
            // honest estimate, which the screen words differently rather than filling in.
            'eta' => ProcessingEstimate::forJob($job)?->toArray(),
        ], JSON_THROW_ON_ERROR);

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Content-Length', (string) strlen($body))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store, private')
            ->withBody($this->streamFactory->createStream($body));
    }
}
