<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review\Move;

use App\AudioToText\Application\ReviewConversationService;
use App\AudioToText\Domain\Exception\ReviewRejected;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Web\Job\Review\ReviewRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

/**
 * Reassigns one turn to the Agent or the Customer
 * (POST /audio-to-text/job/{publicId}/review/turn/{index}/move).
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

        // An unrecognised value is refused rather than resolved to whichever role is not the other:
        // this endpoint records a person's decision about who was speaking, and inferring that
        // decision from a malformed field would be inventing it.
        $role = SpeakerRole::tryFrom($this->support->form($request)->string('role'));

        return $this->support->apply(
            $publicId,
            $role === SpeakerRole::AGENT
                ? 'Turn reassigned to the Agent.'
                : 'Turn reassigned to the Customer.',
            function () use ($publicId, $adminId, $index, $version, $role): void {
                match ($role) {
                    SpeakerRole::AGENT => $this->review->moveToAgent($publicId, $adminId, $index, $version),
                    SpeakerRole::CUSTOMER => $this->review->moveToCustomer($publicId, $adminId, $index, $version),
                    default => throw ReviewRejected::unsupportedRole(),
                };
            },
        );
    }
}
