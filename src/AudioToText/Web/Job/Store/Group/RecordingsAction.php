<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Store\Group;

use App\AudioToText\Application\UploadOptions;
use App\AudioToText\Domain\AudioToTextSettingsRepositoryInterface;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\RecordingType;
use App\AudioToText\Domain\StoreOrderGroup;
use App\AudioToText\Domain\StoreRecordingSlot;
use App\AudioToText\Domain\TranscriptionProvider;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\Job\JobPageGuard;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Router\UrlGeneratorInterface;

use function array_map;
use function json_encode;
use function usort;

use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;

/**
 * Every recording an order holds, current and superseded
 * (GET /audio-to-text/store/{sourceId}/group/{groupKey}/recordings).
 *
 * This is what the Manage Audio dialog reads. It answers three questions the browser must not try to
 * work out for itself: which recording of each kind is the current one, what else has ever been
 * uploaded for that kind, and whether a replacement may be offered.
 *
 * ## A replacement is an upload, not a special kind of write
 *
 * Replacing the caller side means uploading another caller recording for the same order. The database
 * has always allowed that — nothing forbids two conversations sharing a store, an order and a recording
 * type — and the store's read model has always folded them into one cell with the rest kept as history.
 * So there is no new relationship here, no version column and no pointer: a version *is* a conversation,
 * and "current" is decided by {@see \App\AudioToText\Infrastructure\DbStoreOrderGroupRepository::primary()}
 * as the newest one that finished.
 *
 * That is also why nothing needs copying between versions. Storage, transcript, corrections and
 * generated audio all hang off the **job**, and a new version is a new job — so it starts with its own
 * empty transcript and no corrections without a line of code arranging it, and the old recording's
 * files are not in a path this could reach even by accident.
 *
 * ## Why replacement needs an order
 *
 * A group is keyed either by its order or, for an upload that named none, by its own conversation's
 * public id — see {@see \App\AudioToText\Domain\GroupKey}. A replacement is a *new* conversation with a
 * new public id, so for an order-less group it would form a group of its own rather than joining this
 * one, and the two would sit as unrelated rows. Rather than infer a relationship that is not recorded,
 * this says plainly that the order is what makes a replacement possible, and the dialog repeats it.
 */
final readonly class RecordingsAction
{
    public function __construct(
        private StoreGroupFinder $finder,
        /**
         * The same object the store page's own upload form asks — see {@see UploadOptions}.
         *
         * Which providers exist, which this machine can run, and whether paid audio is configured at
         * all. Answered from local configuration only: rendering this form contacts no provider.
         */
        private UploadOptions $uploadOptions,
        private AudioToTextSettingsRepositoryInterface $settingsRepository,
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

        // A legacy Customer + Agent pair is one conversation holding both halves, so there is no such
        // thing as replacing one of them: the pair is the upload. Said once, here, rather than left
        // for the browser to deduce from a missing recording type.
        $replaceable = $group->orderId !== null && !$group->isLegacySeparate();

        return $this->json([
            'orderId' => $group->orderId,
            'canReplace' => $replaceable,
            // The upload options, exactly as the store page's own form renders them.
            'providers' => $this->providers(),
            'aiAudioConfigured' => $this->uploadOptions->aiAudioIsUsable(),
            'reason' => $this->refusal($group),
            'action' => $this->urlGenerator->generate(
                AudioToTextRoute::STORE_GROUP_REPLACE,
                ['sourceId' => $sourceId, 'groupKey' => $groupKey],
            ),
            'slots' => $this->slots($group, $replaceable),
        ]);
    }

    private function refusal(StoreOrderGroup $group): ?string
    {
        return match (true) {
            $group->isLegacySeparate()
                => 'This upload holds both sides of the call in one recording pair, which can only be '
                    . 'replaced as a whole. Upload it again as a new conversion.',
            $group->orderId === null
                => 'This recording was uploaded without an order id, so a replacement would have no '
                    . 'way of joining it. Upload the new recording with an order id instead.',
            default => null,
        };
    }

    /**
     * One entry per kind of recording — including the kinds this order does not have yet.
     *
     * An empty caller slot is offered for upload rather than hidden, because "this order has no caller
     * recording" is exactly the thing an administrator opens this dialog to fix.
     *
     * @return list<array<string, mixed>>
     */
    private function slots(StoreOrderGroup $group, bool $replaceable): array
    {
        if ($group->isLegacySeparate()) {
            // Both halves, read-only: they are roles the uploader supplied, not recording types, and
            // relabelling them Caller and Callee would be inventing a fact. See StoreRecordingSlot.
            return array_map(
                fn(StoreRecordingSlot $slot): array => [
                    'recordingType' => null,
                    'label' => $slot->label(),
                    'canReplace' => false,
                    'versions' => [$this->version($slot, true)],
                ],
                $group->legacySeparate,
            );
        }

        $slots = [];

        foreach (
            [
                [RecordingType::Mixed, $group->mixed],
                [RecordingType::Caller, $group->caller],
                [RecordingType::Callee, $group->callee],
            ] as [$type, $slot]
        ) {
            $slots[] = [
                'recordingType' => $type->value,
                'label' => $type->label(),
                'canReplace' => $replaceable,
                // A replacement is the same call recorded again, so the field starts on the engine
                // that transcribed the recording being replaced. Run through the same preselection
                // the upload form uses, so a provider this machine can no longer run is not offered
                // as the selected one — a select whose only selected option is disabled still submits
                // it, and the server would then refuse a form nobody changed.
                'provider' => $this->uploadOptions->preselected(
                    $slot?->provider ?? $this->settingsRepository->defaultProvider(),
                )->value,
                'versions' => $slot === null ? [] : $this->versions($slot),
            ];
        }

        return $slots;
    }

    /**
     * Every provider, in order, with the label and the availability the upload form shows.
     *
     * An unusable provider is listed and marked rather than hidden, for the reason the upload form
     * gives: an administrator who cannot see the choice cannot tell a single-provider install from a
     * broken one.
     *
     * @return list<array<string, mixed>>
     */
    private function providers(): array
    {
        $usable = $this->uploadOptions->usability();
        $providers = [];

        foreach (TranscriptionProvider::all() as $provider) {
            $providers[] = [
                'value' => $provider->value,
                'label' => $provider->label(),
                'usable' => $usable[$provider->value] ?? false,
            ];
        }

        return $providers;
    }

    /**
     * Every version of one recording, newest upload first.
     *
     * Newest-first rather than current-first, because the newest is what an administrator has just
     * done: a replacement still being transcribed belongs at the top, where it can be seen to be
     * happening, not filed under history it has not become. Which one is *current* is a flag, and the
     * two are deliberately not the same thing — that distinction is the whole feature.
     *
     * @return list<array<string, mixed>>
     */
    private function versions(StoreRecordingSlot $slot): array
    {
        $all = [$slot, ...$slot->older];

        // Back into upload order. The numbers come from the repository, which reads them off the
        // order the database keeps; this only sorts by them rather than inventing a second opinion.
        usort(
            $all,
            static fn(StoreRecordingSlot $a, StoreRecordingSlot $b): int => $b->version <=> $a->version,
        );

        return array_map(
            fn(StoreRecordingSlot $one): array => $this->version($one, $one === $slot),
            $all,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function version(StoreRecordingSlot $slot, bool $current): array
    {
        return [
            'current' => $current,
            'version' => $slot->version,
            'jobPublicId' => $slot->jobPublicId,
            'conversationPublicId' => $slot->conversationPublicId,
            'status' => $slot->status->value,
            // The word the rest of this feature already uses for each state, so the dialog is not a
            // second place that decides what QUEUED is called.
            'statusLabel' => $slot->status->label(),
            'provider' => $slot->provider->value,
            'providerLabel' => $slot->provider->label(),
            'uploadedAt' => $slot->uploadedAt->format(DATE_ATOM),
            'filename' => $slot->originalFilename,
            'durationSeconds' => $slot->durationSeconds,
            // Every link is a route addressed by the job's public id, and every one of those routes
            // resolves through the same administrator gate and the same 404-for-everything rule a
            // current recording uses. A superseded version is not a weaker thing to guard.
            'originalUrl' => $slot->hasOriginalAudio
                ? $this->urlGenerator->generate(
                    AudioToTextRoute::JOB_ORIGINAL_FILE,
                    ['publicId' => $slot->jobPublicId],
                )
                : null,
            'transcriptUrl' => $slot->hasReadableTranscript()
                ? $this->urlGenerator->generate(
                    AudioToTextRoute::JOB_ORIGINAL,
                    ['publicId' => $slot->jobPublicId],
                )
                : null,
            'reviewUrl' => $slot->isReviewable()
                ? $this->urlGenerator->generate(
                    AudioToTextRoute::JOB_REVIEW,
                    ['publicId' => $slot->jobPublicId],
                )
                : null,
            // The old version keeps whatever was generated for it. It is *its* audio, made from *its*
            // transcript, and it stays playable here rather than being carried over to the replacement.
            'aiAudioUrl' => $slot->hasGeneratedAudio()
                ? $this->urlGenerator->generate(
                    AudioToTextRoute::JOB_AI_AUDIO_FILE,
                    ['publicId' => $slot->jobPublicId],
                )
                : null,
            'aiAudioState' => $slot->aiAudioState()->label(),
            // Distinguishes "an earlier recording" from "a replacement that has not finished" and
            // "a replacement that failed" without the browser comparing timestamps.
            'settled' => $slot->status === JobStatus::COMPLETED || $slot->status === JobStatus::FAILED,
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
