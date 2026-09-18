<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\AiAudio\Generate;

use App\AudioToText\Application\Tts\TtsGenerationService;
use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Domain\Tts\TtsException;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Web\AudioToTextRoute;
use App\Auth\Application\CurrentAdmin;
use App\Shared\Web\Flash\FlashMessages;
use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function is_array;
use function is_string;

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
 */
final readonly class Action
{
    public function __construct(
        private TranscriptionJobRepositoryInterface $jobs,
        private AudioConversationRepositoryInterface $conversations,
        private TtsGenerationService $generation,
        private CurrentAdmin $currentAdmin,
        private FlashMessages $flash,
        private Redirect $redirect,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId, ServerRequestInterface $request): ResponseInterface
    {
        $job = $this->jobs->findByPublicId($publicId);

        if ($job === null || $job->conversationId === null) {
            return $this->redirect->toRoute(AudioToTextRoute::JOBS);
        }

        $conversationPublicId = $this->conversations->publicIdFor($job->conversationId);

        if ($conversationPublicId === null) {
            return $this->redirect->toRoute(AudioToTextRoute::JOBS);
        }

        $body = $request->getParsedBody();
        $outputType = TtsOutputType::fromStorage($this->field($body, 'output_type'));

        if ($outputType === null) {
            $this->flash->error('That is not an audio type this recording can produce.');

            return $this->back($conversationPublicId);
        }

        try {
            $outcome = $this->generation->enqueue(
                $job,
                $outputType,
                $this->currentAdmin->get()->id(),
                // Absent means "whatever is current" — but the page always sends it, so an absent value
                // here is a hand-made request rather than a button press.
                $this->field($body, 'expected_hash'),
            );

            $outcome->queued()
                ? $this->flash->success($outcome->message($outputType))
                : $this->flash->info($outcome->message($outputType));
        } catch (TtsException $e) {
            // getMessage() is the administrator-facing half by construction: it carries no path, no
            // command line, no response body and no credential.
            $this->flash->error($e->getMessage());
        }

        return $this->back($conversationPublicId);
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
