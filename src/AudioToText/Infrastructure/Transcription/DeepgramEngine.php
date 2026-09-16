<?php

declare(strict_types=1);

namespace App\AudioToText\Infrastructure\Transcription;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Application\TranscriptText;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\Speaker\TranscriptToken;
use App\AudioToText\Domain\Transcription\AudioTranscriptionResult;
use App\AudioToText\Domain\Transcription\TranscriptionEngineInterface;
use App\AudioToText\Domain\Transcription\TranscriptionRequest;
use App\AudioToText\Domain\TranscriptionProvider;
use App\Shared\Infrastructure\Log\SecretRedactor;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

use function implode;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function rawurlencode;
use function round;
use function sprintf;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Deepgram's hosted speech-to-text. **Console tier only.**
 *
 * The one class in this project that knows Deepgram exists — its URL shape, its query parameters, its
 * JSON. Everything upstream sees {@see TranscriptionEngineInterface}, and everything downstream sees an
 * {@see AudioTranscriptionResult} identical in shape to whisper.cpp's. Swapping providers therefore
 * changes nothing about alignment, diarization, role mapping or the review layer.
 *
 * ## Transcription only
 *
 * No `diarize`, no entity detection, no summarisation, no redaction. Speaker separation stays with the
 * local Sherpa ONNX diarizer, which already works and whose output the whole review UI is built around;
 * asking Deepgram for it too would pay for a second answer nobody reads. The request carries exactly
 * `model`, `language`, `smart_format` and — on Nova-3 — the keyterms.
 *
 * ## The key
 *
 * `reveal()` is called in exactly two places, and the second one exists to protect the first: once to
 * build the Authorization header, and once to seed the {@see SecretRedactor} that scrubs anything this
 * class writes to a log. It is never put in an exception message and never echoed.
 * {@see AudioTranscriptionException} splits the user-facing message from the technical detail, and the
 * key belongs in neither.
 *
 * The redaction is not theoretical tidiness. A failed request's body is quoted into the log so an
 * operator can diagnose it, and that body is written by a third party: it is not this class's place to
 * assume what a remote service will or will not echo back.
 *
 * ## Never reachable from the web
 *
 * This sends audio over the network and blocks on a remote service. `WebTierCannotRunWhisperTest` bans
 * the name `DeepgramEngine` under any `/Web/` path for the same reason it bans `AudioTranscriber`: the
 * upload request must enqueue and return, and readiness questions must be answerable from local
 * configuration alone.
 */
final readonly class DeepgramEngine implements TranscriptionEngineInterface
{
    /**
     * What {@see AudioNormalizer} produces, which is what is uploaded.
     *
     * Deepgram sniffs the container, so this is a courtesy rather than a requirement — but an accurate
     * content type costs nothing and an inaccurate one is a support ticket.
     */
    private const CONTENT_TYPE = 'audio/wav';

    /** Enough of a failed body to diagnose from, short enough not to fill the log with a stack of JSON. */
    private const MAX_LOGGED_BODY = 500;

    /**
     * Scrubs the key, and the usual credential shapes, out of anything quoted into the log.
     *
     * Built once here rather than per failure so the key is revealed at construction and never again on
     * an error path, where a mistake is hardest to notice.
     */
    private SecretRedactor $redactor;

    public function __construct(
        private AudioToTextSettings $settings,
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
        $this->redactor = new SecretRedactor([$settings->deepgram->apiKey->reveal()]);
    }

    public function provider(): TranscriptionProvider
    {
        return TranscriptionProvider::Deepgram;
    }

    public function isAvailable(): bool
    {
        return $this->settings->deepgram->isUsable();
    }

    /**
     * Local configuration only — this does **not** call Deepgram.
     *
     * Verifying the key by calling the API would mean a job failed for "not configured" whenever
     * Deepgram had a bad minute, and would put a network round trip inside the readiness check the
     * upload page also runs. A credential this server holds but Deepgram rejects surfaces as a 401 in
     * {@see recognise()}, where it belongs.
     */
    public function assertReady(): void
    {
        $problems = $this->settings->deepgram->problems();

        if ($problems !== []) {
            throw AudioTranscriptionException::providerNotConfigured(
                $this->provider()->label(),
                implode(' ', $problems),
            );
        }
    }

    public function recognise(TranscriptionRequest $request): AudioTranscriptionResult
    {
        $uri = $this->settings->deepgram->baseUrl . '?' . $this->queryString($request);

        try {
            $body = $this->streamFactory->createStreamFromFile($request->wavPath, 'r');
        } catch (RuntimeException $e) {
            throw AudioTranscriptionException::uploadUnreadable(
                sprintf('the normalised WAV could not be opened at "%s": %s', $request->wavPath, $e->getMessage()),
            );
        }

        $httpRequest = $this->requestFactory
            ->createRequest('POST', $uri)
            // The only place the key is revealed. Nothing downstream of here ever sees it.
            ->withHeader('Authorization', 'Token ' . $this->settings->deepgram->apiKey->reveal())
            ->withHeader('Content-Type', self::CONTENT_TYPE)
            ->withBody($body);

        try {
            $response = $this->httpClient->sendRequest($httpRequest);
        } catch (ClientExceptionInterface $e) {
            // DNS, TLS, connection refused, timeout — the request never got an answer. Distinct from a
            // refusal, because an operator's next move is different: check the network, not the key.
            throw AudioTranscriptionException::deepgramUnreachable($e->getMessage());
        }

        return $this->parse($response);
    }

    /**
     * The query string, with `keyterm` **repeated** rather than indexed or joined.
     *
     * `http_build_query()` cannot be used here. Given `['keyterm' => ['a', 'b']]` it emits
     * `keyterm%5B0%5D=a&keyterm%5B1%5D=b`, which Deepgram reads as a parameter named `keyterm[0]`;
     * given an associative array it silently keeps only the last term. Comma-joining is not the format
     * either. So the pairs are encoded explicitly, in order, and a test asserts the shape of the
     * **final outgoing URI** rather than the array that produced it.
     */
    private function queryString(TranscriptionRequest $request): string
    {
        $deepgram = $this->settings->deepgram;

        $pairs = [
            ['model', $deepgram->model],
            // A per-upload language would fill `languageOverride`; nothing does today, and the
            // configured default is used. The seam exists so adding one needs no change here.
            ['language', $request->languageOverride ?? $deepgram->language],
            ['smart_format', $deepgram->smartFormat ? 'true' : 'false'],
        ];

        // Omitted entirely rather than sent as `numerals=false`, because false is Deepgram's own
        // default: a deployment that has not opted in sends exactly the request it sent before this
        // setting existed, which is what makes enabling it a measurable one-variable change.
        if ($deepgram->numerals) {
            $pairs[] = ['numerals', 'true'];
        }

        // Keyterm Prompting is a Nova-3 feature. Sending it to an older model is a rejected request,
        // not a silent no-op, so a deployment pinned to one simply transcribes without hints.
        if ($deepgram->supportsKeyterms()) {
            foreach ($request->keyterms->toQueryPairs() as $pair) {
                $pairs[] = $pair;
            }
        }

        $encoded = [];
        foreach ($pairs as [$key, $value]) {
            $encoded[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        return implode('&', $encoded);
    }

    private function parse(ResponseInterface $response): AudioTranscriptionResult
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            // Every refusal lands here, including the HTTP 400 that Deepgram answers when the keyterm
            // token budget really is exceeded. That is deliberate: Deepgram is the authority on that
            // limit, and its verdict fails this one job safely with its own message in the log.
            throw AudioTranscriptionException::deepgramRejected($status, $this->snippet($raw));
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw AudioTranscriptionException::deepgramUnreadable('the body was not valid JSON: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw AudioTranscriptionException::deepgramUnreadable('the body was not a JSON object.');
        }

        $channel = $this->channel($decoded);
        $alternative = $this->alternative($channel);

        $transcript = $alternative['transcript'] ?? null;
        if (!is_string($transcript) || trim($transcript) === '') {
            throw AudioTranscriptionException::emptyTranscript();
        }

        return new AudioTranscriptionResult(
            TranscriptText::toValidUtf8(trim($transcript)),
            $this->detectedLanguage($channel),
            $this->tokens($alternative),
        );
    }

    /**
     * @param array<array-key, mixed> $decoded
     *
     * @return array<array-key, mixed>
     */
    private function channel(array $decoded): array
    {
        $results = $decoded['results'] ?? null;
        $channels = is_array($results) ? ($results['channels'] ?? null) : null;
        $channel = is_array($channels) ? ($channels[0] ?? null) : null;

        if (!is_array($channel)) {
            throw AudioTranscriptionException::deepgramUnreadable(
                'results.channels[0] was missing from the response.',
            );
        }

        return $channel;
    }

    /**
     * @param array<array-key, mixed> $channel
     *
     * @return array<array-key, mixed>
     */
    private function alternative(array $channel): array
    {
        $alternatives = $channel['alternatives'] ?? null;
        $alternative = is_array($alternatives) ? ($alternatives[0] ?? null) : null;

        if (!is_array($alternative)) {
            throw AudioTranscriptionException::deepgramUnreadable(
                'results.channels[0].alternatives[0] was missing from the response.',
            );
        }

        return $alternative;
    }

    /**
     * Only what Deepgram actually reported.
     *
     * Present when `language=multi` asked it to detect one; absent when the language was pinned, and
     * null then rather than an echo of the request. The badge on the page says what was *detected*, and
     * repeating the configured value back would make a setting look like a finding.
     *
     * @param array<array-key, mixed> $channel
     */
    private function detectedLanguage(array $channel): ?string
    {
        $language = $channel['detected_language'] ?? null;

        return is_string($language) && $language !== '' ? $language : null;
    }

    /**
     * Word timings, converted to the integer milliseconds the rest of the pipeline speaks.
     *
     * Deepgram reports float seconds; {@see TranscriptToken} is milliseconds, because that is what the
     * diarizer and the aligner already use. The conversion happens here, at the boundary, so no other
     * class has to know two units exist.
     *
     * Missing words are an error rather than an empty success: COMMON mode has nothing to align without
     * them, and a transcript stored with no timings would silently lose speaker separation while looking
     * like it had worked.
     *
     * ## Why each word is emitted with a leading space
     *
     * {@see TranscriptToken} requires a word-initial token to begin with whitespace. Deepgram returns
     * bare words, so the space is added here — at the boundary where the vendor's shape is translated
     * into this pipeline's, which is the same place the seconds-to-milliseconds conversion happens.
     *
     * This is a statement of fact, not padding: Deepgram emits whole words only, never the sub-word
     * fragments whisper.cpp produces, so **every** token from this engine really is word-initial.
     *
     * Getting this wrong was not cosmetic. {@see \App\AudioToText\Application\Speaker\SpeakerTranscriptAligner}
     * reads that leading space twice — once to join tokens into an utterance, and once to decide whether
     * a token continues the previous word and must therefore inherit its speaker. Without it every token
     * looked like a continuation, so an entire two-party call collapsed into one speaker and its text
     * ran together with no spaces at all.
     *
     * @param array<array-key, mixed> $alternative
     *
     * @return list<TranscriptToken>
     */
    private function tokens(array $alternative): array
    {
        $words = $alternative['words'] ?? null;

        if (!is_array($words) || $words === []) {
            throw AudioTranscriptionException::deepgramUnreadable(
                'the response carried a transcript but no word timings, so speaker alignment is impossible.',
            );
        }

        $tokens = [];
        foreach ($words as $word) {
            if (!is_array($word)) {
                continue;
            }

            // `punctuated_word` is what smart_format produced — "Tso," rather than "tso". Preferred so
            // the token stream a reviewer edits matches the transcript they were shown.
            $text = $word['punctuated_word'] ?? $word['word'] ?? null;
            $start = $word['start'] ?? null;
            $end = $word['end'] ?? null;

            if (!is_string($text) || !$this->isNumber($start) || !$this->isNumber($end)) {
                continue;
            }

            $tokens[] = new TranscriptToken(
                (int) round((float) $start * 1000),
                (int) round((float) $end * 1000),
                // The leading space is the word-boundary marker the aligner reads. See the docblock.
                TranscriptText::toValidUtf8(' ' . $text),
            );
        }

        if ($tokens === []) {
            throw AudioTranscriptionException::deepgramUnreadable(
                'no word in the response carried a usable text and timing pair.',
            );
        }

        return $tokens;
    }

    private function isNumber(mixed $value): bool
    {
        return is_float($value) || is_int($value);
    }

    /**
     * A redacted, bounded excerpt of a failed body, for the log only.
     *
     * Redacted **before** truncation, so a secret straddling the cut cannot survive as a fragment.
     * `redactAndTruncate` does both in that order, which is exactly why it exists.
     */
    private function snippet(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return '(empty body)';
        }

        return $this->redactor->redactAndTruncate($trimmed, self::MAX_LOGGED_BODY);
    }
}
