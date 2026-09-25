<?php

declare(strict_types=1);

namespace App\Order58\Web\Calls;

use App\Order58\Domain\CallImportRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\FormData;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function preg_match;

/**
 * Ask for one failed channel to be tried again (POST /admin/order58/calls/retry).
 *
 * ## What may be retried is decided in the statement, not here
 *
 * `CallImportRepositoryInterface::retry()` carries `status = FAILED` in its WHERE and reports whether it
 * matched. So the rule cannot be argued with from this side: a channel the merchant does not produce, a
 * recording past the size limit, and one already imported are all left exactly as they are, whatever is
 * posted. The button is only hidden for them as a courtesy.
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
        $storeId = $this->positiveInt($form->string('store'));

        if ($id === null) {
            $this->flash->error('That import could not be identified.');

            return $this->back($storeId);
        }

        if ($this->imports->retry($id, $this->clock->now())) {
            $this->flash->success('Queued again. The importer will pick it up on its next run.');

            return $this->back($storeId);
        }

        // Either it is not failed, or it is not there. Deliberately one message: distinguishing them
        // would report whether an id exists to anyone who can post one.
        $this->flash->error('That recording is not in a state that can be retried.');

        return $this->back($storeId);
    }

    private function positiveInt(string $raw): ?int
    {
        if (preg_match('/\A\d{1,19}\z/', $raw) !== 1) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    private function back(?int $storeId): ResponseInterface
    {
        return $this->redirect->afterPost(
            'order58.calls',
            $storeId === null ? [] : ['store' => $storeId],
        );
    }
}
