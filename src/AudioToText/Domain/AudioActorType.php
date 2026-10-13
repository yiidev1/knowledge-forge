<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * Which realm performed an audio action — an upload, a transcript correction, a TTS request.
 *
 * ## The vocabulary is lower case, and that is not an accident
 *
 * `audio_segment_revisions.edited_by_type` has stored `'admin'`/`'agent'` since transcript review
 * shipped, under a CHECK that spells them in lower case. Every actor column added since follows it.
 * `order_testing_attempts.initiated_by_type` spells its values in UPPER case instead, because it was
 * written against a different table's precedent.
 *
 * **Both spellings are pre-existing and both are correct in their own table.** Unifying them would mean
 * rewriting rows this feature has no business touching, so each table keeps the vocabulary it already
 * has and the two never meet except through {@see \App\OrderTesting\Domain\TestActorInterface} and this
 * enum, which are translated for separately at the one place that knows both.
 *
 * ## Why an administrator is not simply "the one with an id"
 *
 * `admin_users.id = 7` and Order58's `admin_id = 7` are different people. The audio tables therefore
 * keep **two** id columns per actor — one foreign-keyed to `admin_users`, one holding Order58's own
 * `admin_id` — and this enum says which of the pair is populated. A row may never have both.
 */
enum AudioActorType: string
{
    case Admin = 'admin';
    case Operation = 'operation';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Operation => 'Operation',
        };
    }
}
