<?php

declare(strict_types=1);

namespace App\Order58\Web\CallRecordings;

use App\Order58\Domain\CallImportRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function preg_match;

/**
 * Ask for one failed channel to be fetched again (POST /admin/order58/call-recordings/retry).
 *
 * ## One channel, and only a failed one
 *
 * `CallImportRepositoryInterface::retry()` carries `status = FAILED` in its WHERE and reports whether it
 * matched, so the rule cannot be argued with from this side. That is what makes the three guarantees
 * here free rather than enforced by care:
 *
 * - **A recording already here is never fetched twice.** Its row is `imported`, so the statement matches
 *   nothing and the file on disk is untouched.
 * - **A channel the merchant does not produce offers no button.** It is `not_available` — settled, and
 *   asking again would spend a request to be told the same thing. The template withholds the control,
 *   and the statement would refuse it anyway.
 * - **A channel still being fetched is left alone**, because it is not failed either.
 *
 * A retry clears the attempt count, because a person asking again is new information — something was
 * fixed, or the provider recovered — and holding them to the backoff of a run they did not make would
 * mean waiting an hour to find out.
 */
final readonly class RetryAction
{
    public function __construct(
        private CallImportRepositoryInterface $imports,
        private ClockInterface $clock,
        private Redirect $redirect,
        private FlashMessages $flash,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $form = FormData::fromRequest($request);
        $id = $this->positiveInt($form->string('import'));
        $page = $this->positiveInt($form->string('page'));

        if ($id === null) {
            $this->flash->error('That recording could not be identified.');

            return $this->back($page);
        }

        if ($this->imports->retry($id, $this->clock->now())) {
            $this->flash->success('Download requested again for that recording.');

            return $this->back($page);
        }

        // Either it is not failed, or it is not there. Deliberately one message: distinguishing them
        // would report whether an id exists to anyone who can post one.
        $this->flash->error('That recording is not in a state that can be downloaded again.');

        return $this->back($page);
    }

    private function positiveInt(string $raw): ?int
    {
        if (preg_match('/\A\d{1,19}\z/', $raw) !== 1) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    private function back(?int $page): ResponseInterface
    {
        return $this->redirect->afterPost(
            'order58.call-recordings.history',
            $page === null || $page === 1 ? [] : ['page' => $page],
        );
    }
}
