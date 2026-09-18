<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Tts;

/**
 * Turns one piece of text into one piece of audio.
 *
 * The seam that keeps a paid provider out of the tests and out of the web tier. Every automated test
 * substitutes a fake here, so no suite can reach Deepgram however it is run —
 * {@see \App\Tests\Unit\AudioToText\WebTierCannotRunWhisperTest} additionally makes it a build failure
 * for anything under a `Web/` directory to so much as name the implementation.
 *
 * Deliberately narrow. It does not know about renditions, jobs, transcripts, chunking, silence or
 * merging: it is given text and a voice, and it returns bytes. Everything that decides *what* to say
 * lives in the Application layer, where it can be tested without a network at all.
 */
interface SpeechSynthesizerInterface
{
    /**
     * Speak one chunk.
     *
     * The returned bytes are **raw signed 16-bit little-endian PCM**, mono, at the configured sample
     * rate — not a container. That is what makes joining chunks a byte concatenation rather than an
     * audio-processing problem: there is no header to reconcile, no duration field to correct, and no
     * requirement that the pieces agree about anything beyond the rate they were all requested at.
     *
     * `$text` must already be within the provider's per-request character limit; the caller owns
     * chunking, because only the caller knows where a split is safe.
     *
     * @param string $text  one chunk, never the whole transcript
     * @param string $model the voice, chosen by the caller from the speaker's role
     *
     * @throws TtsException on any refusal, transport failure or unusable response. The message is safe
     *                      to show an administrator and carries no credential.
     */
    public function synthesize(string $text, string $model): string;

    /**
     * Whether this server is configured well enough to try.
     *
     * Local inspection only — it must never open a socket. A provider being down is one generation
     * failing, not a configuration problem, and answering that question here would put a network round
     * trip inside a page render.
     *
     * @throws TtsException when it is not, naming what to fix
     */
    public function assertReady(): void;
}
