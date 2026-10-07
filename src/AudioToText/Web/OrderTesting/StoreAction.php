<?php

declare(strict_types=1);

namespace App\AudioToText\Web\OrderTesting;

use App\AudioToText\Web\Job\Store\Action as AudioStoreAction;
use Psr\Http\Message\ResponseInterface;

/**
 * One store's recordings, on the Order Testing surface.
 *
 * ## Inherited, not copied
 *
 * Reading a store's orders is five careful queries, a pager, an upload form with its own validation and
 * a policy that decides what each recording type becomes. None of that is presentation, and a second
 * copy would be a second place for those rules to drift. So this inherits all of it and changes the
 * three things that are genuinely about *which surface the operator is on*:
 *
 * - the template, so columns, wording and controls can change here without touching the audio page;
 * - where a store id that no longer resolves sends them;
 * - where an accepted upload lands.
 *
 * ## Why an upload returns here rather than to the conversion page
 *
 * The audio page sends an upload on to the conversion screen, which is the right answer there: an
 * administrator who has just uploaded one recording wants to watch it process. Order Testing is worked
 * through an order at a time, and the conversion screen lives at an `/audio-to-text/...` URL — so sending
 * an operator there would move them onto the surface this one exists to keep them off. They come back to
 * the store page, where the row they just added to is waiting.
 */
final readonly class StoreAction extends AudioStoreAction
{
    protected function storeMissing(): ResponseInterface
    {
        return $this->redirect->toRoute(OrderTestingRoute::PAGE);
    }

    protected function afterUpload(string $conversationId, int $sourceId): ResponseInterface
    {
        return $this->redirect->afterPost(OrderTestingRoute::STORE, ['sourceId' => $sourceId]);
    }

    protected function templatePath(): string
    {
        return __DIR__ . '/template';
    }
}
