<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Conversion\AiAudio;

use App\AudioToText\Application\Tts\TtsGenerationService;
use App\AudioToText\Application\Tts\TtsScriptBuilder;
use App\AudioToText\Domain\AudioConversation;
use App\AudioToText\Domain\AudioStore;
use App\AudioToText\Domain\JobStatus;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Domain\Tts\AiAudioState;
use App\AudioToText\Domain\Tts\TtsException;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsRendition;
use App\AudioToText\Domain\Tts\TtsStatus;

/**
 * Everything the AI Audio page renders, decided here so the template decides nothing.
 *
 * The one piece of real logic is {@see state()}, and it is here rather than in a template because it is
 * the staleness rule: a second copy of it — in a view, in a badge helper, anywhere — would eventually
 * disagree with the one the queue uses, and the two disagreeing means either a page that says audio is
 * current when it is not, or a button that charges for audio that already exists.
 */
final readonly class AiAudioPage
{
    /**
     * @param list<AiAudioRow>          $rows              in the order they are rendered
     * @param array<int, TranscriptSource> $transcripts    keyed by job id
     * @param list<string>              $providerProblems why AI audio cannot be generated at all
     */
    public function __construct(
        public AudioConversation $conversation,
        public ?AudioStore $store,
        public array $rows,
        public array $transcripts,
        public bool $providerConfigured,
        public array $providerProblems,
    ) {}

    /**
     * @param list<TranscriptionJob> $jobs                 the conversation's children, fully loaded
     * @param array<int, array<string, TtsRendition>> $renditions keyed by job id, then output type
     * @param list<string>           $providerProblems
     */
    public static function build(
        AudioConversation $conversation,
        ?AudioStore $store,
        array $jobs,
        array $renditions,
        TtsGenerationService $generation,
        TtsScriptBuilder $scripts,
        bool $providerConfigured,
        array $providerProblems,
    ): self {
        $rows = [];
        $transcripts = [];

        foreach ($jobs as $job) {
            foreach ($generation->availableTypes($job) as $outputType) {
                $rendition = $renditions[$job->id][$outputType->value] ?? null;

                // A recording that is not finished, or whose speakers are not published, has no digest to
                // compute and nothing to offer. Asked first so the expensive path is never taken for it.
                if (!$generation->isEligible($job)) {
                    $rows[] = self::blockedRow($job, $outputType, $rendition, $generation, $providerConfigured);
                    $transcripts[$job->id] ??= TranscriptSource::for($job, '');

                    continue;
                }

                try {
                    $script = $scripts->build($job, $outputType);
                    $hash = $generation->currentHash($job, $outputType);
                } catch (TtsException) {
                    // A transcript that cannot be reduced to a digest at all. Vanishingly unlikely, and
                    // the page still has to render: it says nothing can be generated rather than 500.
                    $rows[] = self::blockedRow($job, $outputType, $rendition, $generation, $providerConfigured);
                    $transcripts[$job->id] ??= TranscriptSource::for($job, '');

                    continue;
                }

                $transcripts[$job->id] ??= TranscriptSource::for($job, $hash);

                $state = self::state($rendition, $hash, $generation->currentRenderKey($outputType, $job));
                $nothingToSay = $script->isEmpty();

                $rows[] = new AiAudioRow(
                    $job,
                    $outputType,
                    $rendition,
                    $nothingToSay ? AiAudioState::Unavailable : $state,
                    $hash,
                    $script->characterCount(),
                    $script->turnCount(),
                    $script->omittedTurns,
                    // Offered only when pressing it would actually do something: not while an attempt is
                    // outstanding, not when the audio is already current, not when there is nothing to
                    // say, and not when the provider is unconfigured.
                    $providerConfigured
                        && !$nothingToSay
                        && !$state->isInFlight()
                        && $state !== AiAudioState::Ready,
                    match (true) {
                        $nothingToSay => 'Nothing was said on this side of the call, so there is nothing to read out.',
                        !$providerConfigured => 'AI audio is not configured on this server yet.',
                        $state->isInFlight() => 'A generation is already under way.',
                        default => null,
                    },
                );
            }
        }

        return new self($conversation, $store, $rows, $transcripts, $providerConfigured, $providerProblems);
    }

    /**
     * The whole situation for one output, in one word.
     *
     * Order matters. An outstanding attempt outranks everything, because whatever else is true a worker
     * is about to change it. Then the two "playable but not quite current" cases, which are different
     * sentences and must not be collapsed: a corrected transcript is a reason to regenerate, a changed
     * voice setting is merely an offer.
     */
    private static function state(?TtsRendition $rendition, string $hash, string $renderKey): AiAudioState
    {
        if ($rendition === null) {
            return AiAudioState::NotGenerated;
        }

        return match (true) {
            $rendition->status === TtsStatus::Queued => AiAudioState::Queued,
            $rendition->status === TtsStatus::Generating => AiAudioState::Generating,
            // Checked before the FAILED branch on purpose: a regeneration that failed leaves the previous
            // file playable, and "the words changed" is the more useful thing to say about it.
            $rendition->isStale($hash) => AiAudioState::Stale,
            $rendition->status === TtsStatus::Failed => AiAudioState::Failed,
            !$rendition->matchesRenderKey($renderKey) => AiAudioState::DifferentVoice,
            $rendition->status === TtsStatus::Ready => AiAudioState::Ready,
            default => AiAudioState::NotGenerated,
        };
    }

    /**
     * A row for a recording that cannot be generated from yet, with the remedy named.
     *
     * The message points at the Review screen rather than restating the rule, because the rule is only
     * interesting once and the screen is where the work happens.
     */
    private static function blockedRow(
        TranscriptionJob $job,
        TtsOutputType $outputType,
        ?TtsRendition $rendition,
        TtsGenerationService $generation,
        bool $providerConfigured,
    ): AiAudioRow {
        $reason = match (true) {
            $job->status !== JobStatus::COMPLETED
                => 'This recording has not finished transcribing yet.',
            !$generation->rolesAreKnown($job)
                => 'Agent and Customer have not been confirmed for this call, and a synthetic voice would '
                    . 'assert a speaker this page does not. Confirm the speakers first.',
            default => 'This audio cannot be generated for this recording.',
        };

        return new AiAudioRow(
            $job,
            $outputType,
            $rendition,
            $job->status !== JobStatus::COMPLETED || !$generation->rolesAreKnown($job)
                ? AiAudioState::Blocked
                : AiAudioState::Unavailable,
            '',
            0,
            0,
            0,
            false,
            $providerConfigured ? $reason : 'AI audio is not configured on this server yet.',
        );
    }

    /** Whether the speakers still need confirming, so the page can link to the Review screen once. */
    public function needsSpeakerConfirmation(): bool
    {
        foreach ($this->rows as $row) {
            if ($row->state === AiAudioState::Blocked) {
                return true;
            }
        }

        return false;
    }
}
