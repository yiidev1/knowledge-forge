<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure\Tts;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Domain\Tts\SpeechSynthesizerInterface;
use App\AudioToText\Domain\Tts\TtsException;
use App\Shared\Infrastructure\Log\SecretRedactor;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function implode;
use function json_encode;
use function rawurlencode;
use function strlen;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Deepgram Aura text-to-speech. **Console tier only.**
 *
 * The one class that knows the `/v1/speak` endpoint exists — its query shape, its headers, its failure
 * codes. Everything upstream sees {@see SpeechSynthesizerInterface}, which takes text and a voice and
 * returns bytes, so the logic that decides *what* to say is testable with no network at all.
 *
 * ## Raw PCM, not WAV
 *
 * `container=none` with `encoding=linear16` returns headerless signed 16-bit little-endian mono samples.
 * That is what makes assembling a conversation a byte concatenation: no RIFF header to reconcile between
 * chunks, no placeholder length fields from a streamed response, and silence that is simply a run of
 * zero bytes. See {@see \App\AudioToText\Application\Tts\PcmAudio} for the full reasoning. One ffmpeg
 * call at the end puts the result in a container.
 *
 * ## Chunking belongs to the caller
 *
 * Aura answers HTTP 413 above 2,000 characters. This class does not split anything, because only the
 * caller knows where a split is safe — and because a splitter buried down here could quietly alter text
 * on its way to being spoken, which is the one thing this feature must never do.
 *
 * ## The key
 *
 * `reveal()` is called in exactly two places, and the second exists to protect the first: once to build
 * the Authorization header, and once at construction to seed the {@see SecretRedactor} that scrubs
 * anything this class quotes. Redaction happens **before** truncation, so a key can never survive by
 * being cut in half.
 *
 * That matters more here than it does for transcription, because {@see TtsException}'s user-facing half
 * is stored in `audio_tts_renditions.error_message` and **rendered on an admin page**. A response body
 * is written by a third party; it is not this class's place to assume what a remote service will echo
 * back, so nothing from one reaches a template at all — only the technical half, which goes to the log.
 *
 * ## Never reachable from the web
 *
 * This blocks on a paid remote service. {@see \App\Tests\Unit\AudioToText\WebTierCannotRunWhisperTest}
 * bans this class's name under any `/Web/` path, which makes "the Generate button cannot synchronously
 * call Deepgram" a property of the build rather than a convention someone has to keep.
 */
final readonly class DeepgramSpeechSynthesizer implements SpeechSynthesizerInterface
{
    /** Enough of a failed body to diagnose from, short enough not to fill the log with a stack of JSON. */
    private const MAX_LOGGED_BODY = 500;

    /**
     * Plausibility floor for a 200.
     *
     * At any supported rate this is a few milliseconds of audio. A shorter body is a truncated response
     * rather than a very short reading, and accepting it would put a click in the middle of a sentence.
     */
    private const MINIMUM_PLAUSIBLE_BYTES = 64;

    private SecretRedactor $redactor;

    public function __construct(
        private AudioToTextSettings $settings,
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
        $this->redactor = new SecretRedactor([$settings->tts->apiKey->reveal()]);
    }

    /**
     * Local configuration only — this does **not** call Deepgram.
     *
     * Verifying the key by calling the API would put a paid round trip inside a readiness check that the
     * page also runs, and would report an outage as a misconfiguration. A credential this server holds
     * but Deepgram rejects surfaces as a 401 in {@see synthesize()}, where an operator can tell the two
     * apart.
     */
    public function assertReady(): void
    {
        $problems = $this->settings->tts->problems();

        if ($problems !== []) {
            throw TtsException::notConfigured($problems);
        }
    }

    public function synthesize(string $text, string $model): string
    {
        $tts = $this->settings->tts;
        $uri = $tts->url . '?' . $this->queryString($model);

        try {
            $payload = json_encode(
                ['text' => $text],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            // Only reachable through malformed UTF-8, which TtsSourceText::prepare() has already
            // repaired. Reported as a local fault rather than a provider one, because it is.
            throw TtsException::writeFailed('the request body could not be encoded: ' . $e->getMessage());
        }

        $request = $this->requestFactory
            ->createRequest('POST', $uri)
            // The only place the key is revealed. Nothing downstream of here ever sees it.
            ->withHeader('Authorization', 'Token ' . $tts->apiKey->reveal())
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($payload));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // DNS, TLS, connection refused, timeout — the request never got an answer. Distinct from a
            // refusal because the operator's next move differs: check the network, not the key.
            throw TtsException::unreachable($this->redactor->redactAndTruncate($e->getMessage(), self::MAX_LOGGED_BODY), $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status !== 200) {
            throw $this->refusal($status, $body);
        }

        if (strlen($body) < self::MINIMUM_PLAUSIBLE_BYTES) {
            // A 200 carrying nothing usable. Treated as a failure rather than as silence: writing it out
            // would produce a rendition that reports itself ready and plays a gap.
            throw TtsException::emptyAudio();
        }

        return $body;
    }

    /**
     * The query string.
     *
     * Built by hand rather than with `http_build_query()` for the same reason the transcription engine
     * does it: the exact outgoing shape is what matters, and a helper that re-encodes or reorders is one
     * more thing between the intent and the request.
     *
     * `container=none` is the load-bearing parameter — see the class docblock. `encoding` and
     * `sample_rate` must agree with what the encoder is later told the raw file contains, which is why
     * both read the same setting rather than each carrying a literal.
     */
    private function queryString(string $model): string
    {
        $tts = $this->settings->tts;

        $pairs = [
            ['model', $model],
            ['encoding', 'linear16'],
            ['container', 'none'],
            ['sample_rate', (string) $tts->sampleRate],
        ];

        $parts = [];
        foreach ($pairs as [$name, $value]) {
            $parts[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        return implode('&', $parts);
    }

    /**
     * Turn a non-200 into the right failure.
     *
     * Separated by what an operator would do next, not by status class. A 401 means the credential; a 429
     * means wait; a 413 means the chunker let something through and is a bug here rather than there; a
     * 5xx means try later. Collapsing them into one "request failed" would make every one of those
     * investigations start from scratch.
     */
    private function refusal(int $status, string $body): TtsException
    {
        $detail = $this->snippet($body);

        return match (true) {
            $status === 401 || $status === 403 => TtsException::notAuthorised($status, $detail),
            $status === 413 => TtsException::payloadTooLarge($detail),
            $status === 429 => TtsException::rateLimited($detail),
            $status >= 500 => TtsException::providerUnavailable($status, $detail),
            default => TtsException::refused($status, $detail),
        };
    }

    /** Redacted **then** truncated, so a credential cannot survive by being cut in half. */
    private function snippet(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return '(empty body)';
        }

        return $this->redactor->redactAndTruncate($trimmed, self::MAX_LOGGED_BODY);
    }
}
