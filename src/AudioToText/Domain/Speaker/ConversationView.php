<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\SpeakerRole;
use App\AudioToText\Domain\SpeakerSeparationStatus;

use function array_unique;
use function count;
use function preg_match;

/**
 * Turns a stored separation result into something safe to display.
 *
 * This class exists because three different things were being conflated, and the UI was reading the
 * second as if it were the third:
 *
 *   1. **the neutral diarization speaker** — `SPEAKER_00`, `SPEAKER_01`. Which voice, not whose. Always
 *      trustworthy within a recording, because it is the diarizer's own clustering.
 *   2. **the tentative role hypothesis** — the role mapper's best guess at which cluster is the agent.
 *      Always produced, at any confidence, and stored in `speaker_segments` so an inconclusive mapping
 *      can be second-guessed later. *Evidence, not a finding.*
 *   3. **the published role** — a hypothesis that cleared the confidence threshold and every quality
 *      gate. Only this one may be shown as a fact, and only this one populates `agent_text` /
 *      `customer_text`.
 *
 * `speaker_separation_status` is the sole arbiter of whether (2) has become (3), which is why this class
 * takes the status rather than inspecting the roles on the utterances. A NEEDS_REVIEW result carries
 * fully populated AGENT/CUSTOMER roles in its stored segments — identically to a COMPLETED one — so the
 * roles cannot be used to decide how much to claim about them.
 */
final readonly class ConversationView
{
    /**
     * @param list<ConversationTurn> $turns
     * @param array<string, string>  $hypotheses role label => neutral speaker label, and empty whenever
     *                                           roles are published (a fact needs no hypothesis) or the
     *                                           mapping never reached a usable guess
     */
    private function __construct(
        public array $turns,
        public bool $rolesPublished,
        public array $hypotheses,
        public ?float $confidence,
    ) {}

    /**
     * @param list<SpeakerUtterance> $utterances
     * @param bool $aggregateTextPresent whether `agent_text` and `customer_text` are both populated.
     *                                   A published status without them is a contradiction, and the
     *                                   labels follow the weaker of the two facts rather than the
     *                                   stronger — the four things the invariant names (status,
     *                                   confidence, aggregate text, turn labels) either all agree or
     *                                   nothing is presented as settled.
     */
    public static function from(
        ?SpeakerSeparationStatus $status,
        array $utterances,
        ?float $confidence = null,
        bool $aggregateTextPresent = true,
    ): self {
        $published = $status?->isPublishable() === true && $aggregateTextPresent;

        $turns = [];
        foreach ($utterances as $utterance) {
            $isRole = $utterance->role === SpeakerRole::AGENT || $utterance->role === SpeakerRole::CUSTOMER;

            // The role label is used only when the result as a whole was published. Previously this
            // branched on the utterance's own role, which meant a low-confidence mapping — where every
            // utterance still carries a role — rendered "Agent" and "Customer" exactly like a confident
            // one, while the list page showed the same job as an unpublished split.
            $turns[] = $published && $isRole
                ? new ConversationTurn($utterance->role->label(), $utterance->text, true)
                : new ConversationTurn(self::neutralLabel($utterance->speaker), $utterance->text, false);
        }

        return new self(
            $turns,
            $published,
            $published ? [] : self::hypotheses($utterances),
            $confidence,
        );
    }

    public function isEmpty(): bool
    {
        return $this->turns === [];
    }

    /**
     * `SPEAKER_00` is the diarizer's own naming and means nothing to a reader, so it is numbered from 1
     * for display. The mapping is positional rather than by order of appearance, so the same cluster
     * keeps the same name however the conversation opens.
     */
    private static function neutralLabel(string $speaker): string
    {
        if (preg_match('/^SPEAKER_(\d+)$/', $speaker, $matches) === 1) {
            return 'Speaker ' . ((int) $matches[1] + 1);
        }

        // Tokens that matched no diarization interval at all are stored with the speaker `UNKNOWN`.
        // "Speaker 0" would imply a cluster that does not exist.
        return 'Unidentified speaker';
    }

    /**
     * The mapper's guess, kept only as a guess.
     *
     * Dropped entirely when both roles point at the same cluster: a hypothesis that cannot tell the two
     * apart is not a weaker answer, it is no answer, and showing it would invite it to be read as one.
     *
     * @param list<SpeakerUtterance> $utterances
     *
     * @return array<string, string>
     */
    private static function hypotheses(array $utterances): array
    {
        $dominant = [];

        foreach ([SpeakerRole::AGENT, SpeakerRole::CUSTOMER] as $role) {
            $counts = [];
            foreach ($utterances as $utterance) {
                if ($utterance->role === $role && $utterance->speaker !== 'UNKNOWN') {
                    $counts[$utterance->speaker] = ($counts[$utterance->speaker] ?? 0) + 1;
                }
            }

            if ($counts === []) {
                continue;
            }

            // The winner is read back off the utterance rather than out of the key, so the speaker name
            // stays the string the diarizer produced — a purely numeric cluster name would otherwise
            // come back from the array as an int. Walking the conversation in order also resolves a tie
            // to whichever cluster spoke first, with no extra rule.
            $best = null;
            $bestCount = 0;
            foreach ($utterances as $utterance) {
                if ($utterance->role !== $role || $utterance->speaker === 'UNKNOWN') {
                    continue;
                }

                $count = $counts[$utterance->speaker] ?? 0;
                if ($count > $bestCount) {
                    $best = $utterance->speaker;
                    $bestCount = $count;
                }
            }

            if ($best === null) {
                continue;
            }

            $dominant[$role->label()] = $best;
        }

        if (count($dominant) < 2 || count(array_unique($dominant)) < 2) {
            return [];
        }

        $labels = [];
        foreach ($dominant as $roleLabel => $speaker) {
            $labels[$roleLabel] = self::neutralLabel($speaker);
        }

        return $labels;
    }
}
