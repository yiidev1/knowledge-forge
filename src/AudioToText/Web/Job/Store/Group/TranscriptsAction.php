<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Store\Group;

use App\AudioToText\Application\MachineConversationReader;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\Speaker\ConversationTurn;
use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Domain\Speaker\TranscriptVoice;
use App\AudioToText\Domain\StoreRecordingSlot;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Web\Job\JobPageGuard;
use App\Shared\Application\Time\AppTimeZone;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;

use function json_encode;

use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;

/**
 * Every original transcript of one order, for the store page's read-only modal
 * (GET /audio-to-text/store/{sourceId}/group/{groupKey}/transcripts).
 *
 * ## Machine output, and only machine output
 *
 * This reads through {@see MachineConversationReader} — the machine-only twin of the effective reader —
 * so a correction somebody saved is invisible here however many there have been. That is the whole
 * distinction the two modals draw: this one shows what the transcriber produced, and Details shows the
 * version being worked on. Reading the reviewed columns here would quietly merge the two and leave an
 * administrator no way to compare them.
 *
 * ## Segments are not guaranteed
 *
 * A completed job can hold a transcript with no speaker segments at all — a recording whose speakers
 * were never separated, which includes every legacy Customer + Agent half. The dedicated Original page
 * handles that by redirecting away; a modal cannot, so this falls back to the plain transcript and says
 * so by sending `segments: []` with `plainText` set. No speaker is invented for it, because none is
 * known.
 *
 * ## The group, not a conversation
 *
 * One row of the store page is one order, and an order can hold a mixed, a caller and a callee
 * recording. The modal has a tab per recording, so the endpoint is addressed by the group — resolved
 * through {@see StoreGroupFinder}, which is what stops a key from another store resolving here.
 */
final readonly class TranscriptsAction
{
    public function __construct(
        private StoreGroupFinder $finder,
        private TranscriptionJobRepositoryInterface $jobs,
        private MachineConversationReader $machine,
        private AppTimeZone $appTimeZone,
        private JobPageGuard $guard,
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

        $entries = [];

        // Primaries only. An older recording of the same type is reached through its own row in the
        // history dialog, which keeps this modal about the call as it stands rather than about every
        // attempt at it.
        foreach ($group->primaries() as $slot) {
            $entry = $this->entry($slot);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $this->json([
            'orderId' => $group->orderId,
            'transcripts' => $entries,
        ]);
    }

    /**
     * One recording's transcript, or null when it has nothing to show.
     *
     * @return array<string, mixed>|null
     */
    private function entry(StoreRecordingSlot $slot): ?array
    {
        if ($slot->status !== JobStatus::COMPLETED) {
            return null;
        }

        $job = $this->jobs->findByPublicId($slot->jobPublicId);

        if ($job === null) {
            return null;
        }

        $machine = $this->machine->for($job);

        // The same two objects the Original page builds, called the same way, so a turn here reads
        // exactly as it does there — including the rule that an unconfirmed separation may not print
        // Agent and Customer as fact, which is what the trailing `false` means.
        // The recording type this slot already carries: a Caller recording is one person's words on
        // this screen for the same reason it is on every other, and the read model is where that is
        // decided rather than here.
        $voice = TranscriptVoice::forRecording($slot->recordingType);

        $segments = $machine->isEmpty()
            ? []
            : $this->segments(ConversationView::from(
                $job->speakerSeparationStatus,
                $machine->utterances,
                $job->speakerRoleConfidence,
                $machine->hasSeparatedText(),
                false,
                $voice,
            )->turns);

        $plain = $segments === [] ? $this->plainText($job) : null;

        if ($segments === [] && $plain === null) {
            return null;
        }

        return [
            'type' => $slot->recordingType?->value ?? $slot->sourceRole->value,
            'label' => $slot->label(),
            'conversationPublicId' => $slot->conversationPublicId,
            'jobPublicId' => $slot->jobPublicId,
            'provider' => $slot->provider->label(),
            'duration' => $slot->durationSeconds,
            // Already worded, in the application's timezone — the same one the table behind the modal
            // prints. Handing the browser an instant to format would date the same upload differently
            // for a reader in another country.
            'uploadedAt' => $this->appTimeZone->format($slot->uploadedAt),
            'segments' => $segments,
            // Set only when there are no segments: the modal renders one or the other, never both.
            'plainText' => $plain,
        ];
    }

    /**
     * The turns, in the shape `_partial/thread.php` renders.
     *
     * Deliberately the same field names the Details dialog receives, so **one** renderer in
     * `audio-store.js` draws both: this dialog gets bubbles, that one gets bubbles plus the controls.
     * Two renderers would be two chat designs that start identical and stop being so.
     *
     * @param list<ConversationTurn> $turns
     *
     * @return list<array<string, mixed>>
     */
    private function segments(array $turns): array
    {
        $segments = [];

        foreach ($turns as $turn) {
            $segments[] = [
                'start' => $turn->timing->startMs,
                'end' => $turn->timing->endMs,
                // The label `ConversationView` decided, whatever it is. That object already withholds
                // Agent and Customer until the separation is publishable and hands back a neutral
                // "Speaker 1" instead, so passing it through claims exactly as much as the pipeline
                // does — and `speakerConfirmed` tells the modal which of the two it is looking at.
                'speaker' => $turn->label,
                'speakerConfirmed' => $turn->confirmed,
                'text' => $turn->text,
                // Which way the bubble faces. A reading aid the domain is careful to keep free of any
                // claim about who spoke; the label above the bubble is what names a speaker.
                'side' => $turn->side->value,
                'time' => $turn->timing->rangeLabel(),
                'delay' => $turn->timing->delayLabel(),
                'edited' => $turn->edited,
            ];
        }

        return $segments;
    }

    /**
     * The machine's own flat transcript, when there are no turns to show.
     *
     * `transcript` only — never `reviewed_segments` or either reviewed text column. This modal is the
     * unedited record, and falling back to a correction would make it a different page wearing the
     * same title.
     */
    private function plainText(TranscriptionJob $job): ?string
    {
        $text = trim((string) $job->transcript);

        return $text === '' ? null : $text;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            // A transcript is a customer's words: it is never cached and never sniffed.
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG));

        return $response;
    }
}
