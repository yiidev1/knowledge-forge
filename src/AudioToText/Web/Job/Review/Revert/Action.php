<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review\Revert;

use App\AudioToText\Application\ReviewConversationService;
use App\AudioToText\Web\Job\Review\ReviewRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Discards every correction and returns the job to the machine's own result
 * (POST /audio-to-text/job/{publicId}/review/revert).
 *
 * The confirmation goes with it — with no reviewed layer there is nothing left for it to be about — and
 * the revert is itself recorded, so the history shows the corrections existed and were withdrawn rather
 * than appearing never to have happened.
 */
final readonly class Action
{
    public function __construct(
        private ReviewConversationService $review,
        private ReviewRequest $support,
    ) {}

    public function __invoke(
        #[RouteArgument]
        string $publicId,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $adminId = $this->support->adminId();
        $version = $this->support->expectedVersion($request);

        return $this->support->apply(
            $publicId,
            'Corrections discarded. This conversation is back to the system\'s original result.',
            fn() => $this->review->revert($publicId, $adminId, $version),
        );
    }
}
