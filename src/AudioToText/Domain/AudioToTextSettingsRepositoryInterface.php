<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * The one persisted Audio-to-Text setting: which engine a new upload defaults to.
 *
 * Everything else in this module is configured through `.env` and is a deployment decision. This one is
 * not: an administrator changes it from a page, possibly several times a day, and a `.env` edit needs
 * shell access and a restart. It is also the only Audio-to-Text value another module's page needs to
 * show, which a `.env` variable cannot provide without exporting the whole settings object.
 *
 * A **default**, not a policy: it decides what the upload form preselects, and nothing more. Once a job
 * is queued its own `transcription_provider` is what runs, so changing this never affects work already
 * in the queue. {@see TranscriptionJob::transcriptionProvider()}.
 */
interface AudioToTextSettingsRepositoryInterface
{
    /**
     * The provider a new upload defaults to.
     *
     * Never null: the migration seeds the row, and an unreadable or unrecognised value falls back to
     * Whisper rather than leaving the caller to invent one. That fallback is safe in a way the reverse
     * would not be — Whisper is local, already installed, and costs nothing per minute.
     */
    public function defaultProvider(): TranscriptionProvider;

    /**
     * Replace the default.
     *
     * @param int $adminUserId who changed it, recorded for the audit trail
     */
    public function saveDefaultProvider(TranscriptionProvider $provider, int $adminUserId): void;
}
