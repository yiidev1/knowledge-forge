<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review\Confirm;

use App\AudioToText\Application\ReviewConversationService;
use App\AudioToText\Web\Job\Review\ReviewRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Records that an administrator stands behind the Agent/Customer labels
 * (POST /audio-to-text/job/{publicId}/review/confirm).
 *
 * The only route by which a conversation the machine refused to publish starts showing role labels, and
 * the only one that writes the two reviewed role columns. It is a claim about the recording, so it is
 * recorded as its own auditable operation with the person and the moment attached.
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
            'Speaker roles confirmed. The conversation now shows Agent and Customer.',
            fn() => $this->review->confirmRoles($publicId, $adminId, $version),
        );
    }
}
