<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Store\Group;

use App\Auth\Application\CurrentAdmin;
use App\AudioToText\Application\TranscriptionQueue;
use App\AudioToText\Application\UploadOptions;
use App\AudioToText\Domain\AudioToTextSettingsRepositoryInterface;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\StoreOrderGroup;
use App\AudioToText\Domain\TranscriptionProvider;
use App\AudioToText\Web\Job\JobPageGuard;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function is_array;
use function is_string;
use function json_encode;
use function sprintf;

use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;

/**
 * Upload a replacement for one recording of one order
 * (POST /audio-to-text/store/{sourceId}/group/{groupKey}/replace).
 *
 * ## What this actually does, and what it deliberately does not
 *
 * It enqueues an ordinary upload — the same {@see TranscriptionQueue::enqueueConversation()} the store
 * page's own form calls — carrying this group's store, order and the recording type being replaced. It
 * writes nothing else. It does not touch the recording being replaced, its job row, its retained audio,
 * its transcript, its corrections or its generated audio, and it could not: everything it creates hangs
 * off a **new** conversation with a new public id, and storage is addressed by the new job's public id.
 *
 * So the guarantees that matter are not enforced here by care — they hold because there is no code path
 * from this action to the old recording's data at all.
 *
 * ## Becoming current is something the replacement does, not something this decides
 *
 * The new recording becomes the order's current one when it *completes*, which is the transcription
 * worker's existing status write. Until then, and for ever if it fails, the recording being replaced
 * stays current and stays usable. There is no pointer to swap, so there is no window in which the order
 * has no valid recording, and no race between two replacements: the newest one to finish by upload
 * order wins, and a worker finishing late for an older upload cannot overtake a newer one.
 *
 * ## Paid audio is asked for on this upload or not at all
 *
 * `generate_ai_audio` is read from *this* request and is never inherited from the recording being
 * replaced. The distinction matters because the flag is what the worker reads to queue Deepgram TTS
 * without being asked: inheriting it would mean an administrator correcting a mistaken upload silently
 * pays for audio a second time, for a request they did not make. The box is therefore unticked every
 * time the form is opened, and an unticked box posts nothing at all — which is what makes "off" the
 * reliable default rather than something the form has to remember to say.
 *
 * Ticked, the intent is recorded on the new conversation and **nothing else happens here**. The
 * existing flow takes over: the transcription worker queues the audio when the recording completes, or,
 * if the speakers are not yet known, `ReviewConversationService` queues it the moment they are
 * confirmed. No provider is contacted from this request, and no TTS rule is restated in this file.
 *
 * ## The provider
 *
 * Preselected as the replaced recording's own, because a replacement is the same call recorded again
 * and transcribing it with a different engine would change the comparison for a reason nobody chose.
 * The administrator may override it in the dialog, and whatever arrives is validated by
 * {@see UploadOptions} — the same object, and therefore the same rule, the store page's upload form
 * uses. An unusable provider is refused rather than quietly swapped, since the alternative is a
 * transcription that silently is not the one that was asked for.
 */
final readonly class ReplaceAction
{
    public function __construct(
        private StoreGroupFinder $finder,
        private TranscriptionQueue $queue,
        /**
         * The provider rule and the paid-audio rule, both owned by {@see UploadOptions}.
         *
         * The store page's upload form asks this same object the same two questions. Repeating either
         * rule here would be a second authority over which engine may run and over whether money is
         * spent, and the two would only be found to disagree by something going wrong.
         */
        private UploadOptions $uploadOptions,
        private AudioToTextSettingsRepositoryInterface $settingsRepository,
        private CurrentAdmin $currentAdmin,
        private JobPageGuard $guard,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(
        #[RouteArgument]
        int $sourceId,
        #[RouteArgument]
        string $groupKey,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $group = $this->finder->find($sourceId, $groupKey);

        if ($group === null) {
            return $this->guard->notFound();
        }

        // Same two refusals the dialog is told about when it opens, enforced again here: the dialog
        // not offering a button is a courtesy, not a rule.
        if ($group->orderId === null || $group->isLegacySeparate()) {
            return $this->refused('This recording cannot be replaced from here.');
        }

        $body = $request->getParsedBody();
        $type = RecordingType::fromStorage($this->field($body, 'recording_type'));

        if ($type === null) {
            return $this->refused('Choose which recording to replace.');
        }

        [$provider, $problem] = $this->uploadOptions->provider($body, $this->previous($group, $type));

        if ($problem !== null) {
            return $this->refused($problem);
        }

        // A form that posted nothing falls back to the recording being replaced — and that provider may
        // itself have become unavailable since. Checked here rather than assumed, because the fallback
        // is the one value the select never had to be valid for.
        if (!$this->uploadOptions->canRun($provider)) {
            return $this->refused(sprintf(
                '%s is not configured on this server yet, so it cannot be used for this recording.',
                $provider->label(),
            ));
        }

        $file = $request->getUploadedFiles()['audio'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            return $this->refused('Choose an audio file to upload.');
        }

        try {
            $this->queue->enqueueConversation(
                // One file, one recording: the same shape every modern upload has. SEPARATE is the
                // legacy two-file pair, which this action has already refused.
                ConversationMode::Common,
                $sourceId,
                // Keyed by the role the mode expects, exactly as the upload form supplies it.
                [ConversationMode::Common->childRoles()[0]->value => $file],
                $this->currentAdmin->get()->id(),
                $provider,
                // Only if it was asked for on *this* upload, and only if this server can honour it.
                // Never inherited: see the class docblock.
                $this->uploadOptions->wantsAiAudio($body) && $this->uploadOptions->aiAudioIsUsable(),
                $type,
                $group->orderId,
            );
        } catch (AudioTranscriptionException $e) {
            // The uploader-facing half only. technicalDetail() stays in the log, where the queue put it.
            return $this->refused($e->getMessage());
        }

        return $this->json(200, [
            'success' => true,
            'message' => sprintf(
                '%s replacement uploaded. The current recording stays in use until the new one finishes.',
                $type->label(),
            ),
        ]);
    }

    /**
     * What the recording being replaced was transcribed with, or this server's default.
     *
     * The default the field started on, restated here so a submission that posted no provider at all —
     * an older dialog, a browser that dropped the field — lands on the same engine the form showed
     * rather than on whatever the global setting happens to be.
     */
    private function previous(StoreOrderGroup $group, RecordingType $type): TranscriptionProvider
    {
        $slot = match ($type) {
            RecordingType::Mixed => $group->mixed,
            RecordingType::Caller => $group->caller,
            RecordingType::Callee => $group->callee,
        };

        return $slot?->provider ?? $this->settingsRepository->defaultProvider();
    }

    /** One posted field, as a string or not at all. The body is whatever a browser sent. */
    private function field(mixed $body, string $name): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        $value = $body[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    private function refused(string $message): ResponseInterface
    {
        return $this->json(422, ['success' => false, 'message' => $message]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(int $status, array $payload): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG));

        return $response;
    }
}
