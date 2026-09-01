<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review\Split;

use App\AudioToText\Application\ReviewConversationService;
use App\AudioToText\Web\Job\Review\ReviewRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Cuts one turn in two at a character offset
 * (POST /audio-to-text/job/{publicId}/review/turn/{index}/split).
 *
 * The offset comes from a radio the administrator chose in the rendered text, not from anything they
 * typed. Both halves inherit the parent's timestamps, because there is no recorded time for a boundary
 * drawn inside a turn — see {@see \App\AudioToText\Domain\Speaker\ReviewedTurn::splitAt()}.
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
        // A missing or non-numeric offset becomes 0, which the domain refuses as outside the text.
        $offset = (int) $this->support->form($request)->string('offset');

        return $this->support->apply(
            $publicId,
            'Turn split. Both halves keep the original turn\'s approximate timing.',
            fn() => $this->review->split($publicId, $adminId, $index, $offset, $version),
        );
    }
}
