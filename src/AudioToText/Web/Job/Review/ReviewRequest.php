<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review;

use App\AudioToText\Domain\Exception\ReviewConflict;
use App\AudioToText\Domain\Exception\ReviewRejected;
use App\AudioToText\Web\AudioToTextRoute;
use App\Auth\Application\CurrentAdmin;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The shared half of every correction endpoint: who asked, which version they had, and what to say.
 *
 * Six actions differ only in the one service call they make, so everything around that call lives here
 * once. Chiefly the outcome handling — a refusal and a lost race are the two things an administrator
 * will actually encounter, and each needs to be reported in a way that leaves them able to continue.
 */
final readonly class ReviewRequest
{
    public function __construct(
        private CurrentAdmin $currentAdmin,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function adminId(): int
    {
        return $this->currentAdmin->get()->id();
    }

    /**
     * The `review_count` the page was rendered from.
     *
     * Absent or unparseable becomes -1 rather than 0, because 0 is a real version — the one every
     * never-corrected job has. A missing field must lose the race, not silently win it.
     */
    public function expectedVersion(ServerRequestInterface $request): int
    {
        $form = FormData::fromRequest($request);

        return $form->has('expected_review_count') ? (int) $form->string('expected_review_count') : -1;
    }

    public function form(ServerRequestInterface $request): FormData
    {
        return FormData::fromRequest($request);
    }

    /**
     * Run one correction and turn its outcome into a redirect back to the review page.
     *
     * Post/Redirect/Get throughout, as the chat editor does: the page that follows is re-read from the
     * database, so whatever the administrator sees next is current and the forms on it carry a fresh
     * version — including, and especially, after a conflict.
     *
     * @param callable(): void $operation
     */
    public function apply(string $publicId, string $success, callable $operation): ResponseInterface
    {
        try {
            $operation();
            $this->flash->success($success);
        } catch (ReviewConflict) {
            // Deliberately not the exception's own message: what matters to the person who lost the
            // race is that their change did not happen and that the page below is now the current one.
            $this->flash->error(
                'Somebody else corrected this conversation while you had it open. Your change was not '
                . 'applied. The conversation below is the current version — please make your change again.',
            );
        } catch (ReviewRejected $e) {
            // These messages were written for administrators in the domain layer, so they are shown
            // as-is rather than translated into something vaguer here.
            $this->flash->error($e->getMessage());
        }

        return $this->redirect->afterPost(AudioToTextRoute::JOB_REVIEW, ['publicId' => $publicId]);
    }
}
