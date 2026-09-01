<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review\MoveText;

use App\AudioToText\Application\ReviewConversationService;
use App\AudioToText\Domain\Exception\ReviewRejected;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Web\Job\Review\ReviewRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function is_numeric;

/**
 * Reassigns a selection to the other speaker
 * (POST /audio-to-text/job/{publicId}/review/turn/{index}/move-text).
 *
 * The selection arrives as text rather than as offsets on purpose. A browser counts UTF-16 code units
 * and the domain counts codepoints, so the two disagree the moment a turn contains an emoji or any
 * other astral character — and an off-by-one there would cut a word in half. Sending the words
 * themselves lets the server find them, and lets it refuse when the stored turn no longer contains
 * them because somebody else edited it first.
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
        $form = $this->support->form($request);

        $role = SpeakerRole::tryFrom($form->string('role'));
        $selection = $form->raw('selection');

        // Only ever used to choose between repeats of the same words, so an unparseable value simply
        // means "no preference" rather than an error.
        $rawHint = $form->string('hint');
        $hint = is_numeric($rawHint) ? (int) $rawHint : null;

        return $this->support->apply(
            $publicId,
            $role === SpeakerRole::AGENT
                ? 'Moved to the Agent.'
                : 'Moved to the Customer.',
            function () use ($publicId, $adminId, $index, $selection, $role, $hint, $version): void {
                if ($role !== SpeakerRole::AGENT && $role !== SpeakerRole::CUSTOMER) {
                    throw ReviewRejected::unsupportedRole();
                }

                $this->review->moveText($publicId, $adminId, $index, $selection, $role, $hint, $version);
            },
        );
    }
}
