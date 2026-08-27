<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Download;

use App\AudioToText\Application\TranscriptFilename;
use App\AudioToText\Application\TranscriptText;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Web\Job\JobPageGuard;
use App\Shared\Domain\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function in_array;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Serves a transcript as a `.txt` attachment.
 *
 * **The body comes from the database, never from the request.** The only input is a job id and a part
 * name from a fixed allow-list, so there is nothing a third-party page could make this endpoint echo
 * back — which is also why it is safe as a GET.
 *
 * The filename is rebuilt at download time from the stored original rather than persisted, and folded
 * to a character class that cannot contain a quote, a semicolon or a newline, so `Content-Disposition`
 * can never be made to carry header syntax of its own.
 */
final readonly class Action
{
    /** @var non-empty-list<string> */
    private const PARTS = ['transcript', 'agent', 'customer'];

    public function __construct(
        private TranscriptionJobRepositoryInterface $jobs,
        private JobPageGuard $guard,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private ClockInterface $clock,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId, ServerRequestInterface $request): ResponseInterface
    {
        $job = $this->jobs->findByPublicId($publicId);

        $requested = $request->getQueryParams()['part'] ?? 'transcript';
        $part = is_string($requested) && in_array($requested, self::PARTS, true) ? $requested : 'transcript';

        // A job that is not complete, and a part that was never produced, both collapse to the same 404
        // as a job that does not exist — there is no useful distinction to publish here.
        if ($job === null || $job->status !== JobStatus::COMPLETED) {
            return $this->guard->notFound();
        }

        $text = $this->textFor($job, $part);

        if ($text === null || $text === '') {
            return $this->guard->notFound();
        }

        // Re-checked even though it was validated on the way in: the header promises UTF-8, and that
        // promise has to be true of the bytes as well as the charset parameter.
        $text = TranscriptText::toValidUtf8($text);

        $filename = TranscriptFilename::for(
            $job->originalFilename,
            $job->completedAt ?? $this->clock->now(),
            $part,
        );

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename))
            ->withHeader('Content-Length', (string) strlen($text))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store, private')
            ->withBody($this->streamFactory->createStream($text));
    }

    private function textFor(TranscriptionJob $job, string $part): ?string
    {
        return match ($part) {
            'agent' => $job->agentText,
            'customer' => $job->customerText,
            default => $job->transcript,
        };
    }
}
