<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Status;

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
 * Three enum values and nothing else. No transcript (the page fetches that once, by reloading, rather
 * than re-sending it every two seconds), no filesystem path, no stderr, no process id, no job id, no
 * uploader. The prose for each value lives in the client-side label map, so this endpoint publishes
 * only keys and cannot leak a message that was written for a log.
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
