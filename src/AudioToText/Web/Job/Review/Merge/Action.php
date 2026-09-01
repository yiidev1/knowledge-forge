<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review\Merge;

use App\AudioToText\Application\ReviewConversationService;
use App\AudioToText\Domain\Speaker\MergeDirection;
use App\AudioToText\Web\Job\Review\ReviewRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Joins one turn with the neighbour above or below it
 * (POST /audio-to-text/job/{publicId}/review/turn/{index}/merge).
 *
 * The page disables this where the rule would refuse, but that is a courtesy and not the gate: a page
 * left open while somebody else corrected the conversation can still post a merge that is no longer
 * legal, and the service refuses it exactly as it would have before.
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
        #[RouteArgument]
        int $index,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $adminId = $this->support->adminId();
        $version = $this->support->expectedVersion($request);
        $direction = MergeDirection::tryFrom($this->support->form($request)->string('direction'))
            ?? MergeDirection::Next;

        return $this->support->apply(
            $publicId,
            'Turns joined.',
            function () use ($publicId, $adminId, $index, $version, $direction): void {
                $direction === MergeDirection::Previous
                    ? $this->review->mergeWithPrevious($publicId, $adminId, $index, $version)
                    : $this->review->mergeWithNext($publicId, $adminId, $index, $version);
            },
        );
    }
}
