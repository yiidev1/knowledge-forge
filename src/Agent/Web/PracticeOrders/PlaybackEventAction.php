<?php

declare(strict_types=1);

namespace App\Agent\Web\PracticeOrders;

use App\OrderTesting\Application\Playback\PlaybackContext;
use App\OrderTesting\Application\Playback\PlaybackTracker;
use App\OrderTesting\Domain\Playback\PlaybackEventType;
use App\Shared\Audio\RecordingIdentityReader;
use App\Shared\Web\Actor\CurrentActor;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function is_array;
use function is_scalar;
use function json_encode;

/**
 * What an agent's browser reports about reading one conversation aloud.
 *
 * ## Four things are proved before this runs, and none of them by this class
 *
 * The route sits inside the agent group's practice sub-group, so by the time the action is reached
 * `RequireAgentMiddleware` has proved the session, `RequirePracticeOrdersMiddleware` has published the
 * agent onto {@see CurrentActor} and refused the request unless `PracticeStoreScope::admitsJob()`
 * admits this recording, and the CSRF middleware has checked the token. This class therefore does the
 * one thing left: decide whether the *report* is one the records may accept.
 *
 * ## The body names no one
 *
 * Agent, store, order and recording are all taken from the session and from the route's recording id.
 * The body carries only what happened — an event name, a token, a gap, a message count — so there is
 * no field in which a request could claim to be somebody else or to be listening to something else.
 *
 * ## It answers quietly
 *
 * Every outcome is a small JSON object and a 2xx or 4xx; nothing here is rendered and nothing is
 * redirected. A refused report is not an error the reader should see — their reading is going fine —
 * so the browser is told and says nothing.
 */
final readonly class PlaybackEventAction
{
    public function __construct(
        private PlaybackTracker $tracker,
        private RecordingIdentityReader $recordings,
        private CurrentActor $actor,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        #[RouteArgument]
        string $publicId,
    ): ResponseInterface {
        $agentId = $this->actor->agentId();

        if (!$this->actor->isAgent() || $agentId === null) {
            // Belt as well as braces: the gate ahead of this refuses every other realm, and an
            // administrator who somehow arrived here must not be recorded as an agent.
            return $this->json(['ok' => false], Status::FORBIDDEN);
        }

        $recording = $this->recordings->find($publicId);

        if ($recording === null) {
            return $this->json(['ok' => false], Status::NOT_FOUND);
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $context = new PlaybackContext(
            agentAdminId: $agentId,
            agentDisplayName: $this->actor->displayName(),
            storeSourceId: $recording->storeSourceId,
            sourceOrderId: $recording->sourceOrderId,
            jobPublicId: $recording->jobPublicId,
            recordingLabel: $recording->recordingType,
        );

        $event = PlaybackEventType::tryFrom($this->string($body, 'event'));

        if ($event === null) {
            return $this->json(['ok' => false], Status::UNPROCESSABLE_ENTITY);
        }

        // A dropdown change belongs to no attempt and carries no token. It is recorded on its own and
        // can never become a play — the table's own CHECK is the other half of that.
        if ($event === PlaybackEventType::GapSelected) {
            return $this->tracker->recordSelection($context, $this->string($body, 'gap'))
                ? $this->json(['ok' => true])
                : $this->json(['ok' => false], Status::UNPROCESSABLE_ENTITY);
        }

        $token = $this->string($body, 'session');

        $session = $event === PlaybackEventType::PlayRequested
            ? $this->tracker->request($context, $token, $this->string($body, 'gap'), $this->int($body, 'messages'))
            : $this->tracker->advance(
                $context,
                $token,
                $event,
                $this->has($body, 'played') ? $this->int($body, 'played') : null,
                $this->has($body, 'detail') ? $this->string($body, 'detail') : null,
            );

        if ($session === null) {
            return $this->json(['ok' => false], Status::UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true, 'status' => $session->status->value]);
    }

    /** @param array<array-key, mixed> $body */
    private function has(array $body, string $key): bool
    {
        return isset($body[$key]) && is_scalar($body[$key]);
    }

    /** @param array<array-key, mixed> $body */
    private function string(array $body, string $key): string
    {
        return $this->has($body, $key) ? (string) $body[$key] : '';
    }

    /** @param array<array-key, mixed> $body */
    private function int(array $body, string $key): int
    {
        return $this->has($body, $key) ? (int) $body[$key] : 0;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = Status::OK): ResponseInterface
    {
        return $this->responses
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streams->createStream(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG),
            ));
    }
}
