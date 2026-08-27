<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Speaker;

use App\AudioToText\Domain\Speaker\DialogueAct;
use App\AudioToText\Domain\Speaker\RoleScoreWeights;
use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\SpeakerRole;

use function abs;
use function array_keys;
use function array_slice;
use function arsort;
use function count;
use function in_array;
use function mb_strlen;
use function min;
use function preg_split;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Decides which neutral speaker cluster is the agent and which is the customer.
 *
 * This runs **after** acoustic separation and never in place of it. The clusters arrive already
 * distinguished by voice; all that remains is to work out which voice belongs to the person taking the
 * order. Inferring speakers from the words alone is the mistake this design exists to avoid.
 *
 * ## Why this reads the conversation rather than counting words
 *
 * The previous implementation concatenated everything a speaker said and counted keyword hits. It got
 * obvious calls right by a hair and reported almost no confidence in them — 0.077 on a call where the
 * roles are unmistakable to a human — because in an order call *the two sides use the same vocabulary*:
 *
 *   - "cash" and "card" are substrings of the agent's own question, "cash or card?";
 *   - a competent agent repeats the address back, so "street" and "apartment" land in their transcript;
 *   - the agent recites the order during the recap, so the food words do too.
 *
 * Every one of those is the agent doing their job, and every one scored as evidence of them being the
 * customer. The margin between the two hypotheses collapsed, and confidence with it.
 *
 * What actually separates the roles is not vocabulary but **position in the exchange**: one side asks
 * for the address and the other supplies it. So this class detects {@see DialogueAct}s and scores
 * *adjacency pairs* — a question from one speaker answered by the other. A pair cannot be manufactured
 * by echoing, which is precisely what makes it trustworthy. Isolated phrases still contribute, at a
 * small fraction of the weight, so a mid-call recording with no complete exchange is not left with
 * nothing to go on.
 *
 * Position in the *call* is never used: the first audible voice is not reliably the agent.
 */
final readonly class SpeakerRoleMapper
{
    public function __construct(
        private DialogueActDetector $detector = new DialogueActDetector(),
    ) {}

    /**
     * Assigns roles to clusters and returns the mapping plus a confidence in it.
     *
     * @param list<SpeakerUtterance> $utterances
     *
     * @return array{
     *     utterances: list<SpeakerUtterance>,
     *     confidence: float,
     *     speakers: int,
     *     reason: string|null
     * }
     */
    public function map(array $utterances): array
    {
        $clusters = $this->clusterLengths($utterances);
        $speakerCount = count($clusters);

        if ($speakerCount === 0) {
            return $this->unresolved($utterances, 0, 'no speaker clusters were produced');
        }

        if ($speakerCount === 1) {
            // One voice is not a conversation. It may be a monologue, a voicemail, or a diarizer that
            // failed to separate two similar-sounding speakers — and there is no way to tell which from
            // here, so neither role column is filled.
            return $this->unresolved($utterances, 1, 'only one speaker was detected');
        }

        // With three or more voices, map only the two carrying the conversation and leave the rest as
        // OTHER. This picks the *participants*, by how much they speak; it does not pick their roles,
        // which is decided entirely by the exchanges below. A bystander interjecting six one-word
        // remarks should not displace a participant, hence length rather than utterance count.
        $primary = $this->primarySpeakers($clusters);
        if (count($primary) < 2) {
            return $this->unresolved($utterances, $speakerCount, 'no two speakers carried the conversation');
        }

        [$first, $second] = $primary;

        $evidence = $this->weighEvidence($utterances, $primary);

        $forward = $evidence[$first];
        $backward = $evidence[$second];
        $total = $forward + $backward;

        if ($total <= 0.0) {
            return $this->unresolved($utterances, $speakerCount, 'no role signals were found in either speaker');
        }

        // Two independent requirements, multiplied so that neither can carry a weak case alone.
        //
        //   agreement — how one-sided the evidence is. Contradictory signals cancel here, which is what
        //               lets a handful of consistent exchanges outweigh one misattributed fragment.
        //   volume    — how much evidence there is at all. Without this a single lucky pair in a
        //               two-line call would score a perfect ratio, and publishing on that is guessing.
        $agreement = abs($forward - $backward) / $total;
        $volume = min(1.0, $total / RoleScoreWeights::SUFFICIENT_EVIDENCE);
        $confidence = $agreement * $volume;

        $agent = $forward >= $backward ? $first : $second;
        $customer = $agent === $first ? $second : $first;

        $roles = [$agent => SpeakerRole::AGENT, $customer => SpeakerRole::CUSTOMER];

        $mapped = [];
        foreach ($utterances as $utterance) {
            $role = $roles[$utterance->speaker] ?? SpeakerRole::OTHER;

            if ($utterance->speaker === SpeakerRole::UNKNOWN->value) {
                $role = SpeakerRole::UNKNOWN;
            }

            $mapped[] = $utterance->withRole($role);
        }

        return [
            'utterances' => $mapped,
            'confidence' => $confidence,
            'speakers' => $speakerCount,
            'reason' => null,
        ];
    }

    /**
     * Walks the call in order and accumulates, for each candidate speaker, the weight of the evidence
     * that *that speaker is the agent*.
     *
     * A completed exchange is credited once, to the questioner. Crediting the answer separately would
     * double-count a single piece of evidence, and the answer alone is worth almost nothing anyway —
     * that is exactly the echo the old scoring mistook for a signal.
     *
     * @param list<SpeakerUtterance> $utterances
     * @param list<string>           $primary
     *
     * @return array<string, float>
     */
    private function weighEvidence(array $utterances, array $primary): array
    {
        $evidence = [];
        foreach ($primary as $speaker) {
            $evidence[$speaker] = 0.0;
        }

        /** @var list<array{speaker: string, words: int, acts: list<DialogueAct>}> $turns */
        $turns = [];
        foreach ($utterances as $utterance) {
            $turns[] = [
                'speaker' => $utterance->speaker,
                'words' => $this->wordCount($utterance->text),
                'acts' => in_array($utterance->speaker, $primary, true)
                    ? $this->detector->detect($utterance->text)
                    : [],
            ];
        }

        /** @var array<string, true> $consumed keyed by "<turn index>:<act value>" */
        $consumed = [];

        foreach ($turns as $index => $turn) {
            foreach ($turn['acts'] as $act) {
                if (!$act->isQuestion() || isset($consumed[$index . ':' . $act->value])) {
                    continue;
                }

                $answer = $act->answeredBy();
                if ($answer === null) {
                    continue;
                }

                $answerIndex = $this->findAnswer($turns, $index, $turn['speaker'], $answer, $consumed);
                if ($answerIndex === null) {
                    continue;
                }

                $consumed[$index . ':' . $act->value] = true;
                $consumed[$answerIndex . ':' . $answer->value] = true;

                // The questioner is the agent. This is the strong signal: it takes two speakers to
                // produce and cannot be created by one repeating the other.
                $evidence[$turn['speaker']] += RoleScoreWeights::forPair($act);
            }
        }

        foreach ($turns as $index => $turn) {
            foreach ($turn['acts'] as $act) {
                if (isset($consumed[$index . ':' . $act->value])) {
                    continue;
                }

                // Scaled by how much utterance the act was found in. A strong act on a two-word
                // fragment is as likely to be a misplaced boundary as a real move, and must not be
                // able to cancel a completed exchange.
                $weight = RoleScoreWeights::forUnpaired($act)
                    * RoleScoreWeights::reliabilityOf($turn['words']);

                if ($weight <= 0.0) {
                    continue;
                }

                $other = $turn['speaker'] === $primary[0] ? $primary[1] : $primary[0];

                // An agent move credits its own speaker; a customer move credits the other one, since
                // the question being answered is "which of these two is the agent".
                $beneficiary = $act->expectedRole() === SpeakerRole::AGENT ? $turn['speaker'] : $other;
                $evidence[$beneficiary] += $weight;
            }
        }

        return $evidence;
    }

    /**
     * The nearest following utterance by a *different* speaker that performs the answering act.
     *
     * Bounded by {@see RoleScoreWeights::ANSWER_WINDOW} turns of that other speaker, so a question left
     * hanging does not eventually pair with unrelated speech later in the call. Backchannels from the
     * questioner ("okay", "mm-hm") are skipped rather than counted against the window.
     *
     * @param list<array{speaker: string, words: int, acts: list<DialogueAct>}> $turns
     * @param array<string, true>                                               $consumed
     */
    private function findAnswer(
        array $turns,
        int $questionIndex,
        string $questioner,
        DialogueAct $answer,
        array $consumed,
    ): ?int {
        $seen = 0;

        for ($i = $questionIndex + 1, $n = count($turns); $i < $n; ++$i) {
            if ($turns[$i]['speaker'] === $questioner) {
                continue;
            }

            ++$seen;
            if ($seen > RoleScoreWeights::ANSWER_WINDOW) {
                return null;
            }

            // No minimum length is imposed on the answer: a one-word "Cash." is the *correct* reply to
            // "cash or card?", and refusing it would discard the clearest signal in the call.
            if (in_array($answer, $turns[$i]['acts'], true) && !isset($consumed[$i . ':' . $answer->value])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Words in an utterance, counted without relying on `str_word_count`, which is locale-bound and
     * discards non-ASCII letters — it would report the Spanish and Hindi speech in these recordings as
     * far shorter than it is, and short means "less trusted" here.
     */
    private function wordCount(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? 0 : count($parts);
    }

    /**
     * @param list<SpeakerUtterance> $utterances
     *
     * @return array{utterances: list<SpeakerUtterance>, confidence: float, speakers: int, reason: string}
     */
    private function unresolved(array $utterances, int $speakers, string $reason): array
    {
        $mapped = [];
        foreach ($utterances as $utterance) {
            $mapped[] = $utterance->withRole(SpeakerRole::UNKNOWN);
        }

        return [
            'utterances' => $mapped,
            'confidence' => 0.0,
            'speakers' => $speakers,
            'reason' => $reason,
        ];
    }

    /**
     * @param list<SpeakerUtterance> $utterances
     *
     * @return array<string, int> neutral speaker label => total characters spoken
     */
    private function clusterLengths(array $utterances): array
    {
        $lengths = [];
        foreach ($utterances as $utterance) {
            if ($utterance->speaker === SpeakerRole::UNKNOWN->value) {
                continue;
            }

            $lengths[$utterance->speaker] = ($lengths[$utterance->speaker] ?? 0)
                + mb_strlen($utterance->text);
        }

        return $lengths;
    }

    /**
     * @param array<string, int> $clusters
     *
     * @return list<string>
     */
    private function primarySpeakers(array $clusters): array
    {
        arsort($clusters);

        return array_slice(array_keys($clusters), 0, 2);
    }
}
