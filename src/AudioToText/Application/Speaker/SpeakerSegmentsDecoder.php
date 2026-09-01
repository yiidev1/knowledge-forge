<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Speaker;

use App\AudioToText\Domain\Speaker\SpeakerUtterance;
use App\AudioToText\Domain\SpeakerRole;
use JsonException;

use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Rebuilds stored speaker segments into utterances.
 *
 * Lifted out of the job detail action so that page and the forthcoming reviewed-conversation layer
 * share one implementation. Two copies would drift, and two readers disagreeing about what a stored
 * segment means is precisely how a speaker ends up mislabelled on one screen and not another.
 *
 * Best effort throughout: this powers a review panel, and a column that will not decode should cost
 * that panel and nothing else. The transcript is a separate column and is unaffected.
 *
 * **Unknown keys are ignored by design**, and the two keys reviewed segments add are optional. `approx`
 * marks a boundary an administrator created by splitting a turn, `edited` a turn whose wording they
 * corrected. Neither appears in anything the pipeline writes, so a machine segment decodes to false for
 * both and the pipeline needs no knowledge that they exist.
 *
 * The `role` on a decoded utterance is **evidence, not a finding** — a NEEDS_REVIEW result stores
 * AGENT/CUSTOMER exactly as a published one does. Whether those roles may be shown as fact is decided
 * by {@see \App\AudioToText\Domain\Speaker\ConversationView}, never here.
 */
final readonly class SpeakerSegmentsDecoder
{
    /**
     * @return list<SpeakerUtterance>
     */
    public function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $utterances = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $text = $row['text'] ?? null;
            $speaker = $row['speaker'] ?? null;
            $role = $row['role'] ?? null;
            $startMs = $row['start_ms'] ?? null;
            $endMs = $row['end_ms'] ?? null;
            $confidence = $row['confidence'] ?? null;

            if (!is_string($text) || !is_string($speaker)) {
                continue;
            }

            $utterances[] = new SpeakerUtterance(
                is_int($startMs) ? $startMs : 0,
                is_int($endMs) ? $endMs : 0,
                $speaker,
                SpeakerRole::fromStorage(is_string($role) ? $role : null),
                $text,
                is_numeric($confidence) ? (float) $confidence : 0.0,
                // Present only in reviewed JSON, and only where true. A machine segment has neither
                // key, so it decodes to false without the pipeline knowing these exist.
                ($row['approx'] ?? false) === true,
                ($row['edited'] ?? false) === true,
            );
        }

        return $utterances;
    }
}
