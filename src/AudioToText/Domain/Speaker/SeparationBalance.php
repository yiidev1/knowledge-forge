<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\SpeakerRole;

use function array_keys;
use function array_slice;
use function arsort;
use function count;
use function implode;
use function max;
use function preg_split;
use function sprintf;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Whether the diarizer actually found two speakers, measured on the neutral clusters.
 *
 * This is a **separate question from role confidence**, and conflating them is what let a broken call
 * publish. On `21911549.wav` — a 174-second two-party order call — diarization attributed 98.4% of the
 * speech to one cluster and gave the other a single 2.7-second, three-word turn. The whole call was
 * three utterances. Role mapping then reported *1.000* confidence, entirely correctly: with only one
 * side's dialogue acts present there was nothing to contradict, so the margin was perfect. A confident
 * answer to "which of these two is the agent" says nothing about whether there were two of them.
 *
 * So this class asks the prior question, and `COMPLETED` now requires both.
 *
 * **Deliberately not a balance ratio.** Agents legitimately dominate order calls — in the four known-good
 * recordings the quieter speaker holds 32-44% of the speech, and a call where they held 15% would still
 * be perfectly real. What separates a usable split from an unusable one is not proportion but whether
 * the second speaker is *present at all*: absolute floors on their speech, plus evidence that the two
 * actually took turns.
 *
 * Every threshold below is set from measurement, with the same wide margin on both sides:
 *
 * | metric              | 21911549 (broken) | four good calls | floor |
 * |---------------------|-------------------|-----------------|-------|
 * | quieter speaker     | 1.6%              | 32.6-44.2%      | 5%    |
 * | quieter, seconds    | 2.7               | 20.8-30.5       | 5     |
 * | quieter, words      | 3                 | 30-45           | 10    |
 * | speaker alternations| 3                 | 16-30           | 4     |
 *
 * The broken call fails all four; the weakest good call clears every floor by at least 3x. Nothing here
 * is tuned to a boundary, which is the point — these reject the pathological case, not the merely
 * lopsided one.
 */
final readonly class SeparationBalance
{
    /**
     * Share of attributed speech the quieter of the two clusters must hold.
     *
     * 5%, against 32.6% for the weakest good call. Low enough that a genuinely one-sided conversation
     * still publishes; high enough that "one cluster is effectively the entire call" does not.
     */
    public const MIN_MINOR_SHARE = 0.05;

    /** Seconds of speech. Below this the second "speaker" is a fragment, not a participant. */
    public const MIN_MINOR_SECONDS = 5.0;

    /** Words. Guards the case where a long silence is attributed to a speaker who barely spoke. */
    public const MIN_MINOR_WORDS = 10;

    /**
     * Times the speaker changes across the call.
     *
     * A real phone call alternates constantly; the good recordings do so 16-30 times. Three turns over
     * three minutes is not a conversation that was separated, it is one that was missed.
     */
    public const MIN_ALTERNATIONS = 4;

    private function __construct(
        public int $speakerCount,
        public float $minorShare,
        public float $minorSeconds,
        public int $minorWords,
        public int $alternations,
        public float $longestTurnSeconds,
    ) {}

    /**
     * @param list<SpeakerUtterance> $utterances aligned, carrying neutral speakers; roles are ignored
     *                                           entirely, which is what keeps this independent of them
     */
    public static function of(array $utterances): self
    {
        $seconds = [];
        $words = [];
        $sequence = [];
        $longest = 0.0;

        foreach ($utterances as $utterance) {
            if ($utterance->speaker === SpeakerRole::UNKNOWN->value) {
                continue;
            }

            $duration = max(0, $utterance->endMs - $utterance->startMs) / 1000;
            $seconds[$utterance->speaker] = ($seconds[$utterance->speaker] ?? 0.0) + $duration;
            $words[$utterance->speaker] = ($words[$utterance->speaker] ?? 0) + self::wordCount($utterance->text);
            $sequence[] = $utterance->speaker;
            $longest = max($longest, $duration);
        }

        if (count($seconds) < 2) {
            return new self(count($seconds), 0.0, 0.0, 0, 0, $longest);
        }

        arsort($seconds);
        /** @var list<string> $ranked */
        $ranked = array_slice(array_keys($seconds), 0, 2);
        $quieter = $ranked[1];

        $total = 0.0;
        foreach ($ranked as $speaker) {
            $total += $seconds[$speaker];
        }

        $alternations = 0;
        for ($i = 1, $n = count($sequence); $i < $n; ++$i) {
            if ($sequence[$i] !== $sequence[$i - 1]) {
                ++$alternations;
            }
        }

        return new self(
            count($seconds),
            $total > 0.0 ? $seconds[$quieter] / $total : 0.0,
            $seconds[$quieter],
            $words[$quieter] ?? 0,
            $alternations,
            $longest,
        );
    }

    /**
     * Whether this is a two-speaker separation worth publishing a split from.
     *
     * All four floors must hold. They are not independent symptoms — a diarizer that misses one voice
     * produces a quiet cluster *and* few turns at once — but requiring all four means a single unusual
     * measurement on an otherwise sound call cannot block it.
     */
    public function isUsable(): bool
    {
        return $this->speakerCount >= 2
            && $this->minorShare >= self::MIN_MINOR_SHARE
            && $this->minorSeconds >= self::MIN_MINOR_SECONDS
            && $this->minorWords >= self::MIN_MINOR_WORDS
            && $this->alternations >= self::MIN_ALTERNATIONS;
    }

    /** Why it was refused, in terms someone can check against the recording. */
    public function describe(): string
    {
        if ($this->speakerCount < 2) {
            return 'only one speaker was separated from the audio';
        }

        $parts = [];

        if ($this->minorShare < self::MIN_MINOR_SHARE) {
            $parts[] = sprintf(
                'the quieter speaker holds only %.1f%% of the speech',
                $this->minorShare * 100,
            );
        }

        if ($this->minorSeconds < self::MIN_MINOR_SECONDS) {
            $parts[] = sprintf('only %.1fs of it', $this->minorSeconds);
        }

        if ($this->minorWords < self::MIN_MINOR_WORDS) {
            $parts[] = sprintf('%d word%s in total', $this->minorWords, $this->minorWords === 1 ? '' : 's');
        }

        if ($this->alternations < self::MIN_ALTERNATIONS) {
            $parts[] = sprintf(
                '%d speaker change%s across the whole recording',
                $this->alternations,
                $this->alternations === 1 ? '' : 's',
            );
        }

        return $parts === []
            ? 'the two speakers were separated cleanly'
            : implode('; ', $parts);
    }

    private static function wordCount(string $text): int
    {
        $parts = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? 0 : count($parts);
    }
}
