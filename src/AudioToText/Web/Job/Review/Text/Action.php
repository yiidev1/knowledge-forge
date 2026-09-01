<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review\Text;

use App\AudioToText\Application\ReviewConversationService;
use App\AudioToText\Web\Job\Review\ReviewRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Corrects the wording of one turn
 * (POST /audio-to-text/job/{publicId}/review/turn/{index}/text).
 *
 * Only what readers see changes. `transcript` keeps the machine's original wording — a recording heard
 * as "pikup" still says that in the transcript after an administrator has fixed it for readers, which
 * is what makes the correction auditable rather than a quiet overwrite.
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
        // raw(): the administrator's text is stored as typed, and escaped where it is rendered.
        $text = $this->support->form($request)->raw('text');

        return $this->support->apply(
            $publicId,
            'Wording corrected. The original transcript is unchanged.',
            fn() => $this->review->editText($publicId, $adminId, $index, $text, $version),
        );
    }
}
