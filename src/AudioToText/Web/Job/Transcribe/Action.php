<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Transcribe;

use App\AudioToText\Application\TranscriptionRequestService;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\Job\JobPageGuard;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Router\UrlGeneratorInterface;

use function json_encode;

use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;

/**
 * Asks for the transcript of one recording
 * (POST /audio-to-text/job/{publicId}/transcribe).
 *
 * ## It enqueues and nothing more
 *
 * No audio is read, no file is copied, no speech provider is called and no transcription begins in this
 * request. One row changes state, and the worker that has always done this work picks it up on its own
 * schedule, under its own lock, one recording at a time. Copying the retained recording back into the
 * workspace is the worker's first step for exactly this reason — a multi-megabyte copy does not belong
 * in a request somebody is waiting on.
 *
 * ## One recording
 *
 * The public id names a single recording. A call's mixed, caller and callee sides are three of them, and
 * asking for one says nothing about the other two — see {@see TranscriptionRequestService}.
 *
 * ## Pressing twice is not an error
 *
 * The service's conditional update answers false when the recording had already been asked for, and this
 * reports that as success with a different sentence. It is not a failure: the reader wanted a transcript
 * and a transcript is coming. Treating it as one would invite them to press again.
 */
final readonly class Action
{
    public function __construct(
        private TranscriptionRequestService $transcription,
        private TranscriptionJobRepositoryInterface $jobs,
        private JobPageGuard $guard,
        private ResponseFactoryInterface $responseFactory,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(
        #[RouteArgument]
        string $publicId,
    ): ResponseInterface {
        $job = $this->jobs->findByPublicId($publicId);

        if ($job === null) {
            return $this->guard->notFound();
        }

        // A recording that was never acquired without a transcript, or one already being transcribed, or
        // one already finished. None of them is a thing to ask about, and all three read the same to a
        // reader: this is not a recording awaiting a decision.
        if ($job->status !== JobStatus::NOT_REQUESTED) {
            return $this->json(409, [
                'success' => false,
                'message' => 'This recording is not waiting to be transcribed.',
                'status' => $job->status->value,
                'statusLabel' => $job->status->label(),
            ]);
        }

        $requested = $this->transcription->request($job->id);

        return $this->json(200, [
            'success' => true,
            'message' => $requested
                ? 'Transcription requested.'
                : 'Transcription was already requested for this recording.',
            'status' => JobStatus::QUEUED->value,
            'statusLabel' => JobStatus::QUEUED->label(),
            // Where the dialog follows it from here. The same endpoint every other progress view polls.
            'statusUrl' => $this->urlGenerator->generate(
                AudioToTextRoute::JOB_STATUS,
                ['publicId' => $publicId],
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(int $status, array $payload): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG));

        return $response;
    }
}
