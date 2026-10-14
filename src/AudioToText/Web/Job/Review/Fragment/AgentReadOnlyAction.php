<?php

declare(strict_types=1);

namespace App\AudioToText\Web\Job\Review\Fragment;

/**
 * The read-only fragment, with the audio served from the agent's own addresses.
 *
 * ## Why this exists separately
 *
 * Read-only is not the whole question. An administrator viewing Practice Orders may not *edit* a
 * recording, but they can still reach every audio endpoint in the application, so the inherited
 * addresses are correct for them. An agent cannot: `/audio-to-text/...` is behind a gate that refuses
 * their realm outright.
 *
 * So the agent's fragment names the agent's own file routes. Without this the dialog rendered a player
 * pointed at an address the agent's gate refuses — the control appeared, the bytes never arrived, and
 * the only symptom was a player stuck at 0:00.
 *
 * Both routes are mounted for agents, scoped per recording by `PracticeStoreScope`, so a job id learned
 * elsewhere still serves nothing.
 */
final readonly class AgentReadOnlyAction extends ReadOnlyAction
{
    protected function originalFileRoute(): string
    {
        return 'agent.practice-orders.job.original.file';
    }

    protected function aiAudioFileRoute(): string
    {
        return 'agent.practice-orders.job.ai-audio.file';
    }

    /**
     * No revision history, because an agent has no address to read it from.
     *
     * The history endpoint is not mounted for this realm, and nothing is gained by mounting it: the
     * panel lists who corrected which sentence and when, which is an administrator's audit trail, not
     * part of practising a call. Left in, the dialog fetched the admin address, followed the gate's
     * redirect to the agent home, and wrote that entire page into itself — the conversation was
     * rendered and then buried, which is exactly what "the chat does not show" looked like.
     */
    protected function historyUrl(string $publicId): ?string
    {
        return null;
    }
}
