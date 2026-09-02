<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review\Merge;

use App\AudioToText\Application\ReviewConversationService;
use App\AudioToText\Domain\Speaker\MergeDirection;
use App\AudioToText\Web\Job\Review\ReviewRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function is_numeric;

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
        $form = $this->support->form($request);
        $direction = MergeDirection::tryFrom($form->string('direction')) ?? MergeDirection::Next;

        // A highlighted range moves only those words; without one the whole turn is joined. The two
        // are the same correction at different precision, so they share this route and its guards.
        $partial = $form->has('selection_start') && $form->has('selection_end');

        if (!$partial) {
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

        // Codepoint offsets, converted by the browser from its own UTF-16 counting. Non-numeric input
        // becomes an offset the domain refuses rather than one it silently accepts.
        $start = self::offset($form->string('selection_start'));
        $end = self::offset($form->string('selection_end'));
        $selected = $form->raw('selection_text');

        return $this->support->apply(
            $publicId,
            $direction === MergeDirection::Previous
                ? 'Selected text moved into the previous message.'
                : 'Selected text moved into the next message.',
            function () use ($publicId, $adminId, $index, $direction, $start, $end, $selected, $version): void {
                $this->review->mergeSelection(
                    $publicId,
                    $adminId,
                    $index,
                    $direction,
                    $start,
                    $end,
                    $selected,
                    $version,
                );
            },
        );
    }

    /** -1 for anything unparseable, which the domain refuses as outside the text. */
    private static function offset(string $raw): int
    {
        return is_numeric($raw) ? (int) $raw : -1;
    }
}
