<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Store\Group;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\Tts\TtsGenerationService;
use App\AudioToText\Application\Tts\TtsScriptBuilder;
use App\AudioToText\Domain\StoreRecordingSlot;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Domain\Tts\TtsException;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\Job\JobPageGuard;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Router\UrlGeneratorInterface;

use function json_encode;

use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;

/**
 * What may be generated for one order, and exactly how to ask for it
 * (GET /audio-to-text/store/{sourceId}/group/{groupKey}/tts-options).
 *
 * ## The browser is told, never left to work it out
 *
 * The table's columns are **recording types** — Mixed, Caller, Callee — and the renditions table's
 * `output_type` is **MIXED / CUSTOMER / AGENT**. They are different vocabularies for different things:
 * a caller recording is one mixed-mode job, so its generated audio is a `MIXED` rendition of that job.
 * CALLER is not CUSTOMER and CALLEE is not AGENT, and any code that maps one onto the other will be
 * right until the day somebody uploads a separate pair.
 *
 * So this endpoint answers with the **job public id and the real output type** for each choice, and the
 * form posts those back verbatim. Nothing in JavaScript translates between the two vocabularies,
 * because nothing in JavaScript knows them.
 *
 * ## Eligibility is the existing rule, asked rather than copied
 *
 * `selectable` comes from {@see TtsGenerationService}: whether the job is eligible at all, whether the
 * roles are known, whether there is anything to say, and whether a rendition is already current or in
 * flight. The one gate the service does not own — whether a speech provider is configured on this
 * server at all — is checked here, exactly as the AI-audio page checks it, because there is no service
 * rule to reuse for it.
 *
 * A refused choice is returned **with its reason** rather than omitted: "why can't I generate the
 * caller side" is the question this modal exists to answer.
 */
final readonly class TtsOptionsAction
{
    public function __construct(
        private StoreGroupFinder $finder,
        private TranscriptionJobRepositoryInterface $jobs,
        private TtsGenerationService $generation,
        private TtsScriptBuilder $scripts,
        private AudioToTextSettings $settings,
        private JobPageGuard $guard,
        private UrlGeneratorInterface $urlGenerator,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $sourceId,
        #[RouteArgument]
        string $groupKey,
    ): ResponseInterface {
        $group = $this->finder->find($sourceId, $groupKey);

        if ($group === null) {
            return $this->guard->notFound();
        }

        $configured = $this->settings->ttsIsUsable();
        $options = [];

        foreach ($group->primaries() as $slot) {
            $option = $this->option($slot, $configured);

            if ($option !== null) {
                $options[] = $option;
            }
        }

        return $this->json([
            'orderId' => $group->orderId,
            'providerConfigured' => $configured,
            'options' => $options,
        ]);
    }

    /**
     * One choice, or null when the recording has no job left to speak for it.
     *
     * @return array<string, mixed>|null
     */
    private function option(StoreRecordingSlot $slot, bool $configured): ?array
    {
        $job = $this->jobs->findByPublicId($slot->jobPublicId);

        if ($job === null) {
            return null;
        }

        $types = $this->generation->availableTypes($job);
        $outputType = $types[0] ?? null;

        if ($outputType === null) {
            return null;
        }

        $base = [
            // The UI's vocabulary…
            'recordingType' => $slot->recordingType?->value ?? $slot->sourceRole->value,
            'label' => $slot->label(),
            'conversationPublicId' => $slot->conversationPublicId,
            'jobPublicId' => $slot->jobPublicId,
            // What the Text to Audio column says about this recording right now, and the file to play
            // when there is one. Both come from the same slot the page renders the cell from, so the
            // dialog can refresh that cell after a generation without the browser working out any of
            // it — and the polling below asks this endpoint rather than a second one.
            'cellState' => $slot->aiAudioState()->label(),
            'playUrl' => $slot->hasGeneratedAudio()
                ? $this->urlGenerator->generate(
                    AudioToTextRoute::JOB_AI_AUDIO_FILE,
                    ['publicId' => $slot->jobPublicId],
                )
                : null,
            // …and the storage layer's, which the form posts back unchanged.
            'outputType' => $outputType->value,
            'action' => $this->urlGenerator->generate(
                AudioToTextRoute::JOB_AI_AUDIO_GENERATE,
                ['publicId' => $job->publicId],
            ),
        ];

        if (!$this->generation->isEligible($job)) {
            // Completed-and-roles-known is the service's own gate. Naming which half is missing is what
            // turns a greyed-out radio into something an administrator can act on.
            //
            // A Caller or Callee recording never reaches this branch on the roles half: it declared its
            // side, so there is nothing to confirm. Saying so anyway would be the one wrong answer —
            // there is no action that would clear it.
            return $base + [
                'selectable' => false,
                'expectedHash' => null,
                'state' => 'blocked',
                'reason' => $job->status->value === 'COMPLETED'
                    ? 'Speaker confirmation required.'
                    : 'Transcription has not finished for this recording.',
            ];
        }

        // The one thing the service does not check: whether the voice this recording needs is
        // configured at all. Reported per choice, because an unset Caller voice is a reason that one
        // recording cannot be generated and not a reason to stop the mixed audio beside it.
        $voice = $this->scripts->voiceFor($job);
        $voiceProblem = $voice === null ? null : $this->settings->tts->voiceProblem($voice);

        if ($voiceProblem !== null) {
            return $base + [
                'selectable' => false,
                'expectedHash' => null,
                'state' => 'unavailable',
                'reason' => $voiceProblem,
            ];
        }

        try {
            $hash = $this->generation->currentHash($job, $outputType);
            $script = $this->scripts->build($job, $outputType);
        } catch (TtsException $e) {
            // Degrade rather than 500: the modal should still open and explain itself.
            return $base + [
                'selectable' => false,
                'expectedHash' => null,
                'state' => 'unavailable',
                'reason' => $e->getMessage(),
            ];
        }

        if ($script->isEmpty()) {
            return $base + [
                'selectable' => false,
                'expectedHash' => null,
                'state' => 'unavailable',
                'reason' => 'There is no text to read for this recording.',
            ];
        }

        $existing = $this->generation->find($job, $outputType);
        $current = $existing?->isCurrent($hash, $this->generation->currentRenderKey($outputType, $job)) === true;
        $inFlight = $existing !== null && $existing->status->value !== 'READY' && $existing->status->value !== 'FAILED';

        return $base + [
            'selectable' => $configured && !$current && !$inFlight,
            'expectedHash' => $hash,
            'characters' => $script->characterCount(),
            'turns' => $script->turnCount(),
            'state' => $current ? 'current' : ($inFlight ? 'in-flight' : 'ready'),
            'reason' => match (true) {
                !$configured => 'AI audio is not configured on this server.',
                $inFlight => 'Generation is already running for this recording.',
                $current => 'The generated audio is already up to date with this transcript.',
                default => null,
            },
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG));

        return $response;
    }
}
