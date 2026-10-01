<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Store;

use App\AudioToText\Domain\AudioStoreLookupInterface;
use App\Shared\Audio\PendingRecordingPortInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function json_encode;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * What is still arriving for one store (GET /audio-to-text/store/{sourceId}/arriving).
 *
 * ## One request for the page, and only while something is happening
 *
 * The store page asks this every few seconds and stops the moment the answer says nothing is
 * outstanding. A page with no downloads in flight — which is almost every page view — never asks at all,
 * because the server does not render the attribute that starts the polling.
 *
 * ## Why it answers in counts rather than in HTML
 *
 * A channel that has arrived needs a whole cell the browser cannot build: a player with a duration, the
 * Transcribe control, the Details route. Rather than duplicate that rendering in JavaScript — where it
 * would drift from the template and would have to be kept in step with the vocabulary rules — this
 * reports only what is still coming. The page updates those words in place, and when a recording lands
 * it reloads once so the real cell is drawn by the one piece of code that knows how.
 *
 * ## It cannot be used to look at another store
 *
 * The store is resolved through the same lookup the page itself uses, and an id this administrator
 * cannot reach is a 404 — the same answer as one that does not exist, so a loop over ids learns nothing.
 */
final readonly class ArrivingAction
{
    public function __construct(
        private AudioStoreLookupInterface $stores,
        private PendingRecordingPortInterface $pending,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function __invoke(#[RouteArgument] string $sourceId): ResponseInterface
    {
        $store = $this->stores->findBySourceId((int) $sourceId);

        if ($store === null) {
            return $this->json(['calls' => [], 'active' => false], 404);
        }

        $calls = [];

        foreach ($this->pending->activeForStore($store->sourceId) as $acquisition) {
            $channels = [];

            foreach ($acquisition->channels as $channel => $state) {
                $channels[$channel] = [
                    'state' => $state->value,
                    'label' => $state->label(),
                    // The step mark, decided here so the browser does not map five states to five
                    // marks itself and get it wrong the day a sixth appears.
                    'step' => $state->step(),
                ];
            }

            $calls[$acquisition->callSessionId] = [
                'orderId' => $acquisition->orderId,
                'outcomeLabel' => $acquisition->outcome()->label(),
                // Channels the provider has answered for — never bytes, which nothing upstream reports.
                // This is what the bar draws; the line below it is what says how many arrived.
                'percentChecked' => $acquisition->percentChecked(),
                'progressText' => $acquisition->progressText(),
                // What is actually here, which is the line that must never be inferred from the other.
                'availabilityText' => $acquisition->availabilityText(),
                'currentStep' => $acquisition->currentStep(),
                'available' => $acquisition->available(),
                'channels' => $channels,
            ];
        }

        // The page stops asking on `active: false`, and reloads once so the recordings that landed are
        // drawn as players by the template rather than assembled in the browser.
        return $this->json(['calls' => $calls, 'active' => $calls !== []]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Content-Length', (string) strlen($body))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store, private')
            ->withBody($this->streamFactory->createStream($body));
    }
}
