<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review;

use App\AudioToText\Domain\Speaker\ConversationSide;
use App\AudioToText\Domain\Speaker\MergeRefusal;
use App\AudioToText\Domain\Speaker\SplitPoint;
use App\AudioToText\Domain\Speaker\TurnTiming;
use App\AudioToText\Domain\SpeakerRole;

/**
 * One turn as the correction page needs it: what to show, and which controls are available.
 *
 * Assembled outside the template so the page prints decisions rather than making them. In particular
 * the two {@see MergeRefusal}s come from the domain — the template never re-derives whether a merge is
 * legal, which is what stops the sentence on a disabled button drifting from the rule the service
 * actually enforces.
 */
final readonly class ReviewTurnView
{
    /**
     * @param list<SplitPoint> $splitPoints
     */
    public function __construct(
        public int $index,
        /** Either a confirmed role or a neutral speaker name — the same label the read-only page shows. */
        public string $label,
        public bool $confirmed,
        public string $text,
        /** The stored text, markers and all — what the move endpoint has to be given. */
        public string $rawText,
        public SpeakerRole $role,
        public ConversationSide $side,
        public TurnTiming $timing,
        public bool $approx,
        public bool $edited,
        public MergeRefusal $mergeWithPrevious,
        public MergeRefusal $mergeWithNext,
        public array $splitPoints,
        /**
         * Whether moving this whole turn would also join it to a neighbour.
         *
         * Predicted by running the move on the immutable turns and asking the same
         * {@see MergeRefusal} the service will ask, so the confirmation cannot promise a merge that
         * does not happen. Only meaningful for a whole-turn move: a fragment keeps its parent's
         * diarization speaker, so it will not merge into a neighbour heard as a different voice.
         */
        public bool $mergesIfMovedToAgent = false,
        public bool $mergesIfMovedToCustomer = false,
    ) {}

    public function isAgent(): bool
    {
        return $this->role === SpeakerRole::AGENT;
    }

    public function isCustomer(): bool
    {
        return $this->role === SpeakerRole::CUSTOMER;
    }

    /** Sentence ends first: where a merged-speaker mistake almost always falls. */
    public function canSplit(): bool
    {
        return $this->splitPoints !== [];
    }

    /**
     * @return list<SplitPoint>
     */
    public function sentenceSplitPoints(): array
    {
        $points = [];
        foreach ($this->splitPoints as $point) {
            if ($point->endsSentence) {
                $points[] = $point;
            }
        }

        return $points;
    }
}
