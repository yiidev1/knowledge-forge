<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\AiAudio\Generate;

use App\AudioToText\Application\RecordingVoiceReader;
use App\AudioToText\Application\Tts\TtsGenerationService;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Domain\Tts\TtsException;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Web\AudioToTextRoute;
use App\Auth\Application\CurrentAdmin;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function is_array;
use function is_string;
use function json_encode;
use function str_contains;

use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;

/**
 * Asks for AI audio to be generated (POST /audio-to-text/job/{publicId}/ai-audio/generate).
 *
 * ## It enqueues. It does not generate.
 *
 * No provider is contacted here, no process is started and nothing is written to disk. The request
 * inserts or updates one row and returns a redirect. That is not a stylistic preference: text-to-speech
 * for a long call is dozens of sequential HTTP requests to a third party, and doing that inside a web
 * request would tie up a PHP worker for minutes and hand the administrator a timeout instead of audio.
 *
 * `WebTierCannotRunWhisperTest` makes it structural rather than conventional — it fails the build if any
 * file under a `Web/` directory so much as names the synthesizer.
 *
 * ## Spending money is a POST, and carries two proofs
 *
 * CSRF, because this costs money and a GET that spends is a GET somebody can be tricked into making.
 * And `expected_hash`: the digest the page was rendered from. If the transcript was corrected in another
 * tab since, the button that was pressed was labelled with text nobody has read, and buying that is
 * worse than refusing. It is the idiom `review_count` already uses for speaker corrections.
 *
 * Every duplicate — a double click, a refresh, the automatic trigger arriving alongside this one — is
 * absorbed by {@see TtsGenerationService::enqueue()} and reported honestly rather than silently
 * producing a second charge.
 *
 * ## Two ways of reporting one enqueue
 *
 * A form on the AI audio page submits and follows a redirect back to itself, which is where somebody
 * who navigated to that page expects to end up. The store page's modal asks for the same thing from a
 * listing of twenty orders, and sending them to one recording's page is losing their place to tell
 * them something that fits in a sentence — so when a caller asks for JSON it is given the sentence.
 *
 * The enqueue is identical either way: same service, same eligibility, same `expected_hash`, same
 * idempotency. Only the answer differs.
 */
final readonly class Action
{
    public function __construct(
        private TranscriptionJobRepositoryInterface $jobs,
        private AudioConversationRepositoryInterface $conversations,
        private TtsGenerationService $generation,
        private RecordingVoiceReader $voices,
        private CurrentAdmin $currentAdmin,
        private FlashMessages $flash,
        private Redirect $redirect,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId, ServerRequestInterface $request): ResponseInterface
    {
        $wantsJson = $this->wantsJson($request);
        $job = $this->jobs->findByPublicId($publicId);

        if ($job === null || $job->conversationId === null) {
            return $wantsJson
                ? $this->json(['success' => false, 'message' => 'That recording is no longer available.'], 404)
                : $this->redirect->toRoute(AudioToTextRoute::JOBS);
        }

        $conversationPublicId = $this->conversations->publicIdFor($job->conversationId);

        if ($conversationPublicId === null) {
            return $wantsJson
                ? $this->json(['success' => false, 'message' => 'That recording is no longer available.'], 404)
                : $this->redirect->toRoute(AudioToTextRoute::JOBS);
        }

        $body = $request->getParsedBody();
        $outputType = TtsOutputType::fromStorage($this->field($body, 'output_type'));

        if ($outputType === null) {
            $message = 'That is not an audio type this recording can produce.';

            if ($wantsJson) {
                return $this->json(['success' => false, 'message' => $message], 422);
            }

            $this->flash->error($message);

            return $this->back($conversationPublicId);
        }

        // What the administrator pressed the button in: the recording, not the row it is stored under.
        $recording = $this->recordingLabel($job);

        try {
            $outcome = $this->generation->enqueue(
                $job,
                $outputType,
                $this->currentAdmin->get()->id(),
                // Absent means "whatever is current" — but the page always sends it, so an absent value
                // here is a hand-made request rather than a button press.
                $this->field($body, 'expected_hash'),
            );

            if ($wantsJson) {
                return $this->json([
                    // An outcome that queued nothing because the audio is already current is not a
                    // failure: nothing went wrong and nothing needed doing. The sentence says which.
                    'success' => true,
                    'message' => $outcome->messageFor($recording . ' text-to-audio'),
                    'queued' => $outcome->queued(),
                    'recording' => $recording,
                    'jobPublicId' => $job->publicId,
                    'outputType' => $outputType->value,
                ]);
            }

            $outcome->queued()
                ? $this->flash->success($outcome->message($outputType))
                : $this->flash->info($outcome->message($outputType));
        } catch (TtsException $e) {
            // getMessage() is the administrator-facing half by construction: it carries no path, no
            // command line, no response body and no credential.
            if ($wantsJson) {
                return $this->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            $this->flash->error($e->getMessage());
        }

        return $this->back($conversationPublicId);
    }

    /**
     * What this recording is called on the screen the request came from.
     *
     * The declared type where there is one, the role a legacy half was uploaded under otherwise, and
     * Common / Mixed for everything else — the same three answers
     * {@see \App\AudioToText\Domain\StoreRecordingSlot::label()} gives, for the same reason.
     */
    private function recordingLabel(TranscriptionJob $job): string
    {
        $type = $this->voices->typeFor($job);

        if ($type !== null) {
            return $type->label();
        }

        return $job->sourceRole?->isProvided() === true
            ? $job->sourceRole->label()
            : RecordingType::Mixed->label();
    }

    /**
     * Whether this caller wants an answer rather than a new page.
     *
     * Both signals have to be explicit: a browser sends a wildcard `Accept` header for an ordinary
     * form post, so testing `Accept` alone would flip every existing submission to JSON.
     */
    private function wantsJson(ServerRequestInterface $request): bool
    {
        if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }

        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG));

        return $response;
    }

    /** Post/Redirect/Get, so a refresh cannot re-submit a paid action. */
    private function back(string $conversationPublicId): ResponseInterface
    {
        return $this->redirect->afterPost(
            AudioToTextRoute::CONVERSION_AI_AUDIO,
            ['publicId' => $conversationPublicId],
        );
    }

    private function field(mixed $body, string $name): ?string
    {
        if (!is_array($body) || !is_string($body[$name] ?? null)) {
            return null;
        }

        $value = (string) $body[$name];

        return $value === '' ? null : $value;
    }
}
