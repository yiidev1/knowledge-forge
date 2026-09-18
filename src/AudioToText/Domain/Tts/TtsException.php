<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Tts;

use RuntimeException;
use Throwable;

use function implode;
use function sprintf;

/**
 * Carries two messages, following {@see \App\AudioToText\Domain\AudioTranscriptionException}.
 *
 * `getMessage()` is written for an administrator and is stored in `audio_tts_renditions.error_message`,
 * which is **rendered on a page**. It must never contain a filesystem path, a command line, a response
 * body or anything resembling a credential.
 *
 * `technicalDetail()` is for the log. It may contain those — except a credential, which may not exist in
 * either: every string that could have touched a response passes through a redactor before it reaches
 * this class, and the redaction happens *before* truncation so a key cannot survive by being cut in half.
 *
 * The named constructors are the complete failure vocabulary of text-to-speech. Having them all in one
 * place is what makes it possible to answer "what can go wrong, and what does each one say to whom?"
 * without reading the client.
 */
final class TtsException extends RuntimeException
{
    private function __construct(
        string $userMessage,
        private readonly string $technicalDetail,
        /**
         * Whether trying again unchanged could plausibly work.
         *
         * A rate limit or a 5xx is worth another attempt; a missing voice model or an empty transcript
         * will fail identically every time, and retrying it only spends money to learn that again.
         */
        private readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($userMessage, 0, $previous);
    }

    /** Safe for the log only. Never render this. */
    public function technicalDetail(): string
    {
        return $this->technicalDetail === '' ? $this->getMessage() : $this->technicalDetail;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    // ---------------------------------------------------------------- configuration

    /**
     * @param list<string> $problems from {@see \App\AudioToText\Application\Settings\TtsSettings::problems()}
     */
    public static function notConfigured(array $problems): self
    {
        return new self(
            'AI audio is not configured on this server yet. An administrator needs to finish setting up '
            . 'the text-to-speech provider before audio can be generated.',
            'TTS settings incomplete: ' . implode(' ', $problems),
        );
    }

    public static function encoderMissing(string $encoder): self
    {
        return new self(
            'This server cannot write the audio format AI audio is configured to produce. An '
            . 'administrator needs to check the ffmpeg installation.',
            sprintf('ffmpeg does not report the "%s" encoder', $encoder),
        );
    }

    // ---------------------------------------------------------------- nothing to say

    /**
     * There is no transcript to speak.
     *
     * Distinct from a failure: nothing is wrong with the provider, the recording simply produced no words
     * for this output. Saying so plainly stops an administrator hunting for a configuration problem.
     */
    public static function nothingToSpeak(TtsOutputType $type): self
    {
        return new self(
            sprintf(
                'There is no transcript text to generate %s from. Nothing was spoken for this side of '
                . 'the call, or the transcript is empty.',
                $type->label(),
            ),
            sprintf('effective transcript produced zero utterances for %s', $type->value),
        );
    }

    /**
     * The speakers have not been published, so no voice may be assigned.
     *
     * The message names the remedy rather than the rule, because the remedy is a screen the administrator
     * can already reach and the rule is only interesting once.
     */
    public static function rolesNotKnown(): self
    {
        return new self(
            'Agent and Customer have not been confirmed for this call yet, and a synthetic voice would '
            . 'assert a speaker this page does not. Confirm the speakers first, then generate.',
            'roles are neither provided at upload nor published by review',
        );
    }

    /**
     * The transcript could not be reduced to a digest.
     *
     * Only reachable through malformed UTF-8 that survived repair. It is an exception rather than a
     * fallback value because there is no safe fallback: any placeholder would compare equal to itself,
     * so two unhashable renditions would each report themselves as up to date and neither would ever be
     * regenerated.
     */
    public static function digestFailed(string $detail): self
    {
        return new self(
            'This transcript could not be prepared for speech. It may contain characters that are not '
            . 'valid text.',
            'canonical digest encoding failed: ' . $detail,
        );
    }

    public static function outputNotAvailable(TtsOutputType $type): self
    {
        return new self(
            sprintf('%s cannot be produced for this recording.', $type->label()),
            sprintf('%s is not in the availability matrix for this conversation shape', $type->value),
        );
    }

    // ---------------------------------------------------------------- the provider

    /** @param string $detail already redacted and truncated by the caller */
    public static function notAuthorised(int $status, string $detail): self
    {
        return new self(
            'The speech provider rejected this server\'s credentials. The API key may be wrong, or the '
            . 'account may not include text-to-speech.',
            sprintf('Deepgram TTS returned HTTP %d: %s', $status, $detail),
        );
    }

    /** @param string $detail already redacted and truncated by the caller */
    public static function rateLimited(string $detail): self
    {
        return new self(
            'The speech provider is rate limiting this server. The audio was not generated; try again '
            . 'shortly.',
            'Deepgram TTS returned HTTP 429: ' . $detail,
            true,
        );
    }

    /** @param string $detail already redacted and truncated by the caller */
    public static function payloadTooLarge(string $detail): self
    {
        return new self(
            'The speech provider refused part of this transcript as too long to read in one request.',
            'Deepgram TTS returned HTTP 413 despite chunking: ' . $detail,
        );
    }

    /** @param string $detail already redacted and truncated by the caller */
    public static function refused(int $status, string $detail): self
    {
        return new self(
            'The speech provider refused this request. The audio was not generated.',
            sprintf('Deepgram TTS returned HTTP %d: %s', $status, $detail),
        );
    }

    /** @param string $detail already redacted and truncated by the caller */
    public static function providerUnavailable(int $status, string $detail): self
    {
        return new self(
            'The speech provider is temporarily unavailable. The audio was not generated; try again '
            . 'shortly.',
            sprintf('Deepgram TTS returned HTTP %d: %s', $status, $detail),
            true,
        );
    }

    /** @param string $detail already redacted and truncated by the caller */
    public static function unreachable(string $detail, ?Throwable $previous = null): self
    {
        return new self(
            'This server could not reach the speech provider. The audio was not generated; try again '
            . 'shortly.',
            'Deepgram TTS transport failure: ' . $detail,
            true,
            $previous,
        );
    }

    /**
     * A 200 with nothing usable in it.
     *
     * Treated as a failure rather than as silence: writing an empty file would produce a rendition that
     * looks ready and plays nothing, which is worse than an error nobody has to diagnose twice.
     */
    public static function emptyAudio(): self
    {
        return new self(
            'The speech provider returned no audio for part of this transcript. The audio was not '
            . 'generated.',
            'Deepgram TTS returned HTTP 200 with an empty body',
            true,
        );
    }

    // ---------------------------------------------------------------- local failures

    public static function writeFailed(string $detail): self
    {
        return new self(
            'The generated audio could not be saved on this server. Nothing was changed, and any '
            . 'previous audio is still available.',
            'generated audio write failed: ' . $detail,
        );
    }

    public static function encodeFailed(string $detail): self
    {
        return new self(
            'The generated audio could not be assembled into a playable file. Any previous audio is '
            . 'still available.',
            'ffmpeg encode failed: ' . $detail,
        );
    }

    public static function encodeTimedOut(int $seconds): self
    {
        return new self(
            'Assembling the generated audio took too long and was stopped. Any previous audio is still '
            . 'available.',
            sprintf('ffmpeg encode exceeded %ds', $seconds),
            true,
        );
    }

    /**
     * The worker was interrupted and the row was recovered by the stale sweep.
     *
     * Not retried automatically. A generation killed mid-run may well have been killed by the thing that
     * would kill it again, and here the loop would also be billed each time round.
     */
    public static function abandoned(int $afterSeconds): self
    {
        return new self(
            'Generating this audio was interrupted and did not finish. Any previous audio is still '
            . 'available. Generate again when you are ready.',
            sprintf('rendition left GENERATING for more than %ds and was recovered', $afterSeconds),
        );
    }
}
