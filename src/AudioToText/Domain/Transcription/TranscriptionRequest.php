<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Transcription;

/**
 * One recording, ready to be recognised, plus whatever context an engine may use.
 *
 * The WAV has already been normalised to 16 kHz mono by the time this is built, so every engine gets
 * the same bytes — the same bytes the diarizer will read afterwards. That is what keeps the two
 * timelines aligned without an offset.
 *
 * ## Why a request object rather than more parameters
 *
 * Engines differ in what they can accept, and that difference must not leak upward as
 * `if ($provider === …)` at the call site. An engine takes this object and uses the parts it
 * understands: Whisper reads only `$wavPath`; Deepgram reads all three. Adding a capability later —
 * per-store vocabulary, a per-upload language — means filling a field here, not changing a signature
 * or teaching the orchestrator about a provider.
 *
 * `$languageOverride` is that seam, already in place and deliberately unused: the language is a
 * deployment setting today, and a future per-upload selector fills this without `DeepgramEngine`
 * changing at all.
 */
final readonly class TranscriptionRequest
{
    /**
     * @param string          $wavPath          16 kHz mono PCM, produced by AudioNormalizer
     * @param DeepgramKeyterms $keyterms        recognition hints; empty is the normal case today
     * @param string|null     $languageOverride overrides the configured language for this one
     *                                          recording. Null means "use the configured default",
     *                                          which is what every caller does today.
     */
    public function __construct(
        public string $wavPath,
        public DeepgramKeyterms $keyterms,
        public ?string $languageOverride = null,
    ) {}

    public static function forFile(string $wavPath): self
    {
        return new self($wavPath, DeepgramKeyterms::none());
    }

    public function withKeyterms(DeepgramKeyterms $keyterms): self
    {
        return new self($this->wavPath, $keyterms, $this->languageOverride);
    }
}
