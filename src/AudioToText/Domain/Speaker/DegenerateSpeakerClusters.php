<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use function array_keys;
use function asort;
use function count;

/**
 * Whether the diarizer's two clusters are one speaker plus that speaker's own cross-talk.
 *
 * ## The failure this names
 *
 * `FastClusteringConfig(num_clusters: 2)` is a **fixed count**, not a ceiling: the clusterer must
 * return exactly two groups whatever the recording contains. On a call where the two voices are
 * acoustically close, agglomerative linkage merges *them* before it absorbs the handful of fragments
 * where both people talk at once — and those fragments are embedding-space mixtures of both voices, so
 * they sit as far from either speaker as the speakers sit from each other. The one available cut then
 * lands between `{both speakers}` and `{the cross-talk}` instead of between the two people.
 *
 * Measured on `22539329.wav`, which is the recording that prompted this:
 *
 * | | this call | a call that separates correctly |
 * |---|---|---|
 * | embedding separability (between / within speaker) | **1.94x** | 2.90x |
 * | cross-speaker overlap | 4.0% of the call | 1.7% |
 * | smaller cluster at `num_clusters: 2` | 4 segments, **4 of 4 fully inside a larger-cluster segment** | 12 segments, 8 of them standalone turns |
 *
 * That last row is the signature, and it is structural rather than a threshold: a cluster made *only*
 * of intervals that sit inside another cluster's intervals is not a second party taking turns. It is
 * the moments the two of them spoke over each other, wearing a speaker label.
 *
 * ## Why such a cluster is already lost
 *
 * {@see \App\AudioToText\Application\Speaker\SpeakerTranscriptAligner} gives each token to the segment
 * it overlaps most, and for an inner segment N inside an outer segment M, `overlap(token, N)` can never
 * exceed `overlap(token, M)`. So a shadowed segment wins no words, the aligned transcript comes back
 * with one speaker, and {@see SeparationBalance} finds a minor share of zero. Every recording this
 * predicate is true of was already on its way to NEEDS_REVIEW — which is what makes acting on it safe.
 *
 * ## What it deliberately does not match
 *
 * - **One cluster.** A genuinely single-voiced recording, and a CALLER or CALLEE side uploaded on its
 *   own, have nothing shadowed and nothing to recover; a second clustering pass would only invent a
 *   speaker. Exactly two clusters, or nothing.
 * - **Three or more clusters.** The clusterer was already given room and used it.
 * - **A smaller cluster holding even one standalone turn.** That cluster is a speaker who was audible
 *   by themselves at some point, which is all a two-party split needs.
 *
 * Pure and read-only. Nothing here decides a status; it only says that one diarization result is
 * degenerate, and the caller decides what to do about that.
 */
final class DegenerateSpeakerClusters
{
    /**
     * @param list<SpeakerSegment> $segments in any order
     */
    public static function describes(array $segments): bool
    {
        if (count($segments) < 2) {
            return false;
        }

        /** @var array<string, int> $spoken */
        $spoken = [];

        foreach ($segments as $segment) {
            $spoken[$segment->speaker] = ($spoken[$segment->speaker] ?? 0) + $segment->durationMs();
        }

        if (count($spoken) !== 2) {
            return false;
        }

        asort($spoken);
        [$minor, $major] = array_keys($spoken);

        // A tie names no smaller cluster, so there is nothing to call the shadow of the other.
        if ($spoken[$minor] >= $spoken[$major]) {
            return false;
        }

        $minorSegments = 0;
        $shadowed = 0;

        foreach ($segments as $segment) {
            if ($segment->speaker !== $minor) {
                continue;
            }

            ++$minorSegments;

            foreach ($segments as $other) {
                if ($other->speaker !== $major) {
                    continue;
                }

                if ($other->startMs <= $segment->startMs && $other->endMs >= $segment->endMs) {
                    ++$shadowed;

                    break;
                }
            }
        }

        return $minorSegments > 0 && $shadowed === $minorSegments;
    }
}
