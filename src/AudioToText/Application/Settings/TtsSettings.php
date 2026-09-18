<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Settings;

use App\AudioToText\Domain\Tts\TtsOutputFormat;
use App\Shared\Domain\ValueObject\SecretValue;

use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * Everything the Deepgram Aura text-to-speech client needs, read once from `.env`.
 *
 * Mirrors {@see DeepgramSettings} in shape, and is deliberately a **separate object from it** even
 * though the two share an API key. The reason is a failure mode rather than tidiness: the transcription
 * worker validates its settings at startup and refuses to claim anything when they are wrong, so folding
 * text-to-speech into that group would let a typo in a voice name stop every transcription on the
 * machine. Nothing in the transcription path reads this class.
 *
 * The API key is the same `DEEPGRAM_API_KEY` the speech-to-text engine uses. Reused rather than
 * duplicated: one secret to rotate, one place to leak from, and a second variable holding the same value
 * would eventually hold a different one.
 */
final readonly class TtsSettings
{
    public function __construct(
        public SecretValue $apiKey,
        public string $url,
        /**
         * The two voices, one per role.
         *
         * They are separate settings rather than a list because the mapping is the feature: a listener
         * has to be able to tell who is speaking without reading anything, and that only works if the
         * Customer sounds like the Customer on every call in the training set.
         */
        public string $customerModel,
        public string $agentModel,
        public int $sampleRate,
        public int $maxCharactersPerRequest,
        public int $timeoutSeconds,
        /** Silence inserted between TURNS of a mixed conversation, never inside one turn. */
        public int $gapMilliseconds,
        public TtsOutputFormat $outputFormat,
        public int $maxAttempts,
    ) {}

    /**
     * Local configuration problems that stop AI audio being generated.
     *
     * **Inspects settings only.** Nothing here opens a socket: Deepgram being unreachable is one
     * generation failing, not an operator mistake, and reporting them the same way would send somebody to
     * edit `.env` over an outage.
     *
     * Read by the TTS worker at startup and by the page that offers the button. **Never** by
     * {@see \App\AudioToText\Application\AudioToTextSettings::problems()} — see the class docblock.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->apiKey->isEmpty()) {
            $problems[] = 'DEEPGRAM_API_KEY is not set.';
        }

        if ($this->url === '') {
            $problems[] = 'DEEPGRAM_TTS_URL is empty.';
        } elseif (!str_starts_with($this->url, 'https://') && !str_starts_with($this->url, 'http://')) {
            $problems[] = 'DEEPGRAM_TTS_URL must be an absolute http(s) URL.';
        } elseif (str_contains($this->url, '?')) {
            // The client appends model, encoding, container and sample rate itself. A URL that already
            // carries a query string would produce two `?` separators and an unparseable request.
            $problems[] = 'DEEPGRAM_TTS_URL must not contain a query string.';
        }

        if ($this->customerModel === '') {
            $problems[] = 'DEEPGRAM_TTS_MODEL_CUSTOMER is empty.';
        }

        if ($this->agentModel === '') {
            $problems[] = 'DEEPGRAM_TTS_MODEL_AGENT is empty.';
        }

        // Two identical voices would produce a mixed file in which nobody can tell the speakers apart —
        // which is the entire point of the mixed file. Caught here rather than after it has been paid
        // for, because the audio would be technically valid and practically useless.
        if ($this->customerModel !== '' && $this->customerModel === $this->agentModel) {
            $problems[] = sprintf(
                'DEEPGRAM_TTS_MODEL_CUSTOMER and DEEPGRAM_TTS_MODEL_AGENT are both "%s". The two roles '
                . 'must use different voices, or a mixed conversation cannot be followed by ear.',
                $this->customerModel,
            );
        }

        return $problems;
    }

    public function isUsable(): bool
    {
        return $this->problems() === [];
    }

    /**
     * The voice for one side of the conversation.
     *
     * A single method rather than two reads at each call site, so "which voice is the Agent?" has one
     * answer and a mixed render cannot disagree with a per-role one.
     */
    public function modelFor(bool $isAgent): string
    {
        return $isAgent ? $this->agentModel : $this->customerModel;
    }

    /**
     * Bytes of silence per second of audio at the configured rate.
     *
     * Signed 16-bit mono, so two bytes a sample. Kept here because the sample rate lives here and the
     * arithmetic is only correct alongside it.
     */
    public function bytesPerSecond(): int
    {
        return $this->sampleRate * 2;
    }

    /**
     * Advisory notes — never a reason to refuse.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        // The documented Aura ceiling is 2000 characters and the response above it is a 413, which costs
        // a round trip to discover. Running right at the line leaves no room for any disagreement about
        // what counts as a character.
        if ($this->maxCharactersPerRequest > 1950) {
            $warnings[] = sprintf(
                'DEEPGRAM_TTS_MAX_CHARS is %d, close to the documented 2000-character Aura limit. A '
                . 'request that exceeds it is rejected with 413 after it has been sent.',
                $this->maxCharactersPerRequest,
            );
        }

        return $warnings;
    }
}
