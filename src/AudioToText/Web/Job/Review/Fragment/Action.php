<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review\Fragment;

use App\AudioToText\Application\RecordingVoiceReader;
use App\AudioToText\Application\ConversationHistoryBuilder;
use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\EffectiveConversationReader;
use App\AudioToText\Application\SpokenPrice;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\ReviewOperation;
use App\AudioToText\Domain\SegmentRevision;
use App\AudioToText\Domain\SegmentRevisionRepositoryInterface;
use App\AudioToText\Application\Tts\TtsGenerationService;
use App\AudioToText\Application\Tts\TtsScriptBuilder;
use App\AudioToText\Domain\Speaker\MergeRefusal;
use App\AudioToText\Domain\Speaker\ConversationView;
use App\AudioToText\Domain\Speaker\ReviewedConversationTurns;
use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Domain\Tts\TtsRenditionRepositoryInterface;
use App\AudioToText\Web\AudioToTextRoute;
use App\AudioToText\Web\Conversion\AiAudio\AiAudioPage;
use App\AudioToText\Web\Job\JobPageGuard;
use App\AudioToText\Web\Job\Review\ReviewPageView;
use App\Shared\Application\Time\AppTimeZone;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Router\UrlGeneratorInterface;

use function json_encode;
use function sprintf;

use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;

/**
 * One recording's correction state, as data
 * (GET /audio-to-text/job/{publicId}/review/fragment).
 *
 * ## Why this exists beside the review page rather than instead of it
 *
 * The page at `/review` is unchanged and remains the full editor — including the two operations that
 * genuinely need it, dragging a turn between speakers and merging a text selection, both of which
 * depend on measuring the page's own scroll container. This endpoint serves the *other* way in: the
 * Details modal on the store page, which needs the same turns and the same version but has no page to
 * measure.
 *
 * ## It decides nothing
 *
 * Every field here comes from {@see ReviewPageView::build()} — the same view model the page renders,
 * built from the same reader, the same turns and the same audit trail. Whether a merge is refused, what
 * a turn is called, whether the roles may be confirmed and which `review_count` the next write must
 * carry are all settled there. This is a second *rendering*, never a second set of rules, and the
 * corrections it offers post to the seven existing routes, which run the existing service.
 */
final readonly class Action
{
    public function __construct(
        private TranscriptionJobRepositoryInterface $jobs,
        private JobPageGuard $guard,
        private EffectiveConversationReader $conversations,
        private SegmentRevisionRepositoryInterface $revisions,
        private ConversationHistoryBuilder $history,
        private UrlGeneratorInterface $urlGenerator,
        private AppTimeZone $appTimeZone,
        private ResponseFactoryInterface $responseFactory,
        private RecordingVoiceReader $voices,
        private TtsGenerationService $generation,
        private TtsScriptBuilder $scripts,
        private TtsRenditionRepositoryInterface $renditions,
        private AudioToTextSettings $settings,
    ) {}

    public function __invoke(#[RouteArgument] string $publicId): ResponseInterface
    {
        $job = $this->jobs->findByPublicId($publicId);

        if ($job === null) {
            return $this->guard->notFound();
        }

        $effective = $this->conversations->for($job);

        // The same gate the page applies. A job with nothing to correct has nothing to put in a modal
        // either, and answering 404 keeps "no such job" and "nothing to show" indistinguishable.
        if ($job->status !== JobStatus::COMPLETED || $effective->isEmpty()) {
            return $this->guard->notFound();
        }

        $turns = $job->isReviewed()
            ? ReviewedConversationTurns::fromJson($job->reviewedSegmentsJson)
            : ReviewedConversationTurns::fromUtterances($effective->utterances);

        $revisions = $this->revisions->forJob($job->id);

        // Named at upload time: a Caller or Callee recording is one person's words, whatever the
        // diarizer found inside it, and nothing below may offer to re-decide that.
        $voice = $this->voices->for($job);

        $page = ReviewPageView::build(
            $job,
            ConversationView::from(
                $job->speakerSeparationStatus,
                $effective->utterances,
                $job->speakerRoleConfidence,
                $effective->hasSeparatedText(),
                $effective->rolesConfirmed,
                $voice,
            ),
            $turns,
            $this->confirmedBy($revisions),
            $this->history->build($revisions, $turns),
            $voice,
        );

        $rows = [];

        // A recording with a named speaker has no other speaker to move a turn to, so the control is
        // withheld from the payload rather than hidden in the browser: an operation the server would
        // refuse must not be one the browser can compose a request for.
        $canMove = $page->voice === null;

        foreach ($page->turns as $turn) {
            // Where "Move" sends this turn. Decided here, exactly as the page decides it, because the
            // opposite of a role is a domain question: a modal that worked it out from a label would
            // be the second place in this application that believes it knows what Agent is not.
            $other = $turn->isAgent() ? SpeakerRole::CUSTOMER : SpeakerRole::AGENT;

            $rows[] = [
                'index' => $turn->index,
                'label' => $turn->label,
                'confirmed' => $turn->confirmed,
                // What a reader sees, already through the same price formatter the page uses — and
                // only while the text is still the machine's own, which is what `isReviewed` decides.
                'display' => SpokenPrice::formatUnlessReviewed($turn->text, $page->isReviewed),
                // What the editor is seeded with and what Save posts back: the stored wording, not the
                // normalised reading of it. A price the reader sees tidied must never be saved back as
                // though a person had typed it that way.
                'text' => $turn->text,
                'role' => $turn->role->value,
                // `left` / `right` / `neutral` — a reading aid, not a claim about who spoke. The
                // modifier is composed where it is used; this sends the domain's own value.
                'side' => $turn->side->value,
                'time' => $turn->timing->rangeLabel(),
                // The pause before this turn, where TurnTiming judges one worth reporting. The same
                // line the conversation pages print under a bubble, so the dialog reads identically.
                'delay' => $turn->timing->delayLabel(),
                'edited' => $turn->edited,
                'approx' => $turn->approx,
                // Whether this message has anything to show under the clock icon. `TurnLineage`
                // decides, from the audit trail — not from whether the turn looks edited, which a
                // revert or a confirmation would both get wrong.
                'hasHistory' => $turn->hasHistory(),
                'canMove' => $canMove,
                'targetRole' => $canMove ? $other->value : null,
                'targetLabel' => $canMove ? $other->label() : null,
                // Whether moving this whole turn would also join it to a neighbour. Predicted by the
                // view from the same rule the service applies, so the confirmation cannot promise a
                // merge that does not happen.
                'moveMerges' => $canMove && ($other === SpeakerRole::AGENT
                    ? $turn->mergesIfMovedToAgent
                    : $turn->mergesIfMovedToCustomer),
                // The server's own verdict on each merge, reason and all, so the modal offers what the
                // domain would accept and explains what it would not.
                'mergePrevious' => $this->merge($turn->mergeWithPrevious),
                'mergeNext' => $this->merge($turn->mergeWithNext),
                'urls' => [
                    // `move-text`, not `move` — this is the route the correction page's own move
                    // confirmation posts to, sending the whole turn as the selection. The two
                    // screens must perform one operation and write one kind of audit row, so the
                    // dialog uses the endpoint the page uses rather than the simpler neighbour.
                    'moveText' => $canMove
                        ? $this->turnUrl(AudioToTextRoute::JOB_REVIEW_MOVE_TEXT, $publicId, $turn->index)
                        : null,
                    'text' => $this->turnUrl(AudioToTextRoute::JOB_REVIEW_TEXT, $publicId, $turn->index),
                    'merge' => $this->turnUrl(AudioToTextRoute::JOB_REVIEW_MERGE, $publicId, $turn->index),
                ],
            ];
        }

        return $this->json([
            'version' => $page->version,
            'isReviewed' => $page->isReviewed,
            'rolesPublished' => $page->rolesPublished,
            'canConfirm' => $page->canConfirm,
            'confirmBlockedReason' => $page->confirmBlockedReason,
            // Formatted here, in the application's timezone, because that is the timezone the page
            // beside it prints. A browser left to format the instant itself would date the same
            // confirmation differently for a reader in another country.
            'confirmedLine' => $page->confirmedAt === null ? null : sprintf(
                'Roles confirmed by %s on %s.',
                $page->confirmedByUsername ?? 'an administrator',
                $this->appTimeZone->format($page->confirmedAt),
            ),
            // What this recording is, when the upload said. Null for a conversation.
            'voice' => $page->voice?->label,
            // The three ways to hear this recording. Computed here so the dialog renders decisions
            // rather than making them — in particular the staleness rule, which has exactly one home.
            'audio' => $this->audio($job, $publicId),
            'filename' => $job->originalFilename,
            'provider' => $job->transcriptionProvider()->label(),
            'turns' => $rows,
            'urls' => [
                'full' => $this->urlGenerator->generate(AudioToTextRoute::JOB_REVIEW, ['publicId' => $publicId]),
                'confirm' => $this->urlGenerator->generate(
                    AudioToTextRoute::JOB_REVIEW_CONFIRM,
                    ['publicId' => $publicId],
                ),
                'revert' => $this->urlGenerator->generate(
                    AudioToTextRoute::JOB_REVIEW_REVERT,
                    ['publicId' => $publicId],
                ),
                'history' => $this->urlGenerator->generate(
                    AudioToTextRoute::JOB_REVIEW_HISTORY,
                    ['publicId' => $publicId],
                ),
            ],
        ]);
    }

    /**
     * What can be listened to for this recording, and in what state.
     *
     * Two of the three are server questions and are answered here. The original is a file that either
     * survived retention or did not. The generated rendition's state — current, stale, queued, failed,
     * blocked — comes from {@see AiAudioPage::rowsFor()}, which is the same code the AI audio page
     * renders from; a second copy of that rule would eventually disagree with the one the queue uses,
     * and disagreeing means either a page claiming audio is current when it is not or a button
     * charging for audio that already exists.
     *
     * The third, the browser's own voice, needs nothing from the server at all.
     *
     * @return array<string, mixed>
     */
    private function audio(TranscriptionJob $job, string $publicId): array
    {
        $original = [
            'available' => $job->retainedAudioPath !== null,
            // The existing hardened route: it resolves the stored name through the one path builder
            // for retained recordings and composes nothing of its own.
            'url' => $this->urlGenerator->generate(
                AudioToTextRoute::JOB_ORIGINAL_FILE,
                ['publicId' => $publicId],
            ),
        ];

        [$rows] = AiAudioPage::rowsFor(
            [$job],
            [$job->id => $this->renditions->forJobs([$job->id])[$job->id] ?? []],
            $this->generation,
            $this->scripts,
            $this->settings->ttsIsUsable(),
        );

        $row = $rows[0] ?? null;

        if ($row === null) {
            // No output this recording can produce at all. The dialog says so rather than offering
            // a control that would be refused.
            return ['original' => $original, 'generated' => null];
        }

        return [
            'original' => $original,
            'generated' => [
                'state' => $row->state->name,
                'label' => $row->state->label(),
                'title' => $row->title(),
                'playable' => $row->isPlayable(),
                'playUrl' => $row->isPlayable()
                    ? $this->urlGenerator->generate(
                        AudioToTextRoute::JOB_AI_AUDIO_FILE,
                        ['publicId' => $publicId],
                    )
                    : null,
                'canGenerate' => $row->canGenerate,
                'buttonLabel' => $row->buttonLabel(),
                'reason' => $row->blockedReason,
                // Everything a Generate needs, named by the server: the exact output type this
                // recording produces and the digest the dialog was rendered from. The browser echoes
                // them back and the endpoint revalidates both.
                'action' => $this->urlGenerator->generate(
                    AudioToTextRoute::JOB_AI_AUDIO_GENERATE,
                    ['publicId' => $publicId],
                ),
                'outputType' => $row->outputType->value,
                'expectedHash' => $row->currentHash,
            ],
        ];
    }

    private function turnUrl(string $route, string $publicId, int $index): string
    {
        return $this->urlGenerator->generate($route, ['publicId' => $publicId, 'index' => $index]);
    }

    /**
     * One merge direction: whether there is a neighbour at all, whether the rule allows it, and why not.
     *
     * `NoNeighbour` is `available: false` rather than a refusal — the page omits the button entirely at
     * the ends of a conversation instead of showing one that explains it has nothing to join to.
     *
     * @return array{available: bool, allowed: bool, reason: string|null}
     */
    private function merge(MergeRefusal $refusal): array
    {
        return [
            'available' => $refusal !== MergeRefusal::NoNeighbour,
            'allowed' => $refusal->isAllowed(),
            'reason' => $refusal->reason(),
        ];
    }

    /**
     * Who confirmed the roles, read from the audit trail exactly as the page reads it.
     *
     * Ascending, last match wins: a revert clears the confirmation, and confirming again writes a
     * second row. Returning the first would name whoever confirmed it before the revert.
     *
     * @param list<SegmentRevision> $revisions already loaded, oldest first
     */
    private function confirmedBy(array $revisions): ?string
    {
        $username = null;

        foreach ($revisions as $revision) {
            if ($revision->operation === ReviewOperation::ConfirmRoles) {
                $username = $revision->editedByUsername;
            }
        }

        return $username;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG));

        return $response;
    }
}
