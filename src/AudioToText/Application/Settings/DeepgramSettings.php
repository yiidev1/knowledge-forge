<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Settings;

use App\AudioToText\Domain\Transcription\DeepgramKeyterms;
use App\Shared\Domain\ValueObject\SecretValue;

use function str_starts_with;
use function str_contains;

/**
 * Everything the Deepgram engine needs, read once from `.env`.
 *
 * Mirrors {@see DiarizationSettings}: a readonly group assembled in the DI file, with the checks that
 * decide whether this provider can run at all attached to it. Those checks live here rather than in the
 * engine because two callers need them and only one of them may name the engine — the worker's
 * `assertReady()` and the upload page's "can I offer this provider?" question. One definition, two
 * readers, no duplication and no web-tier dependency on Infrastructure.
 *
 * The API key is a {@see SecretValue}: it is revealed exactly once, when the Authorization header is
 * built, and never reaches a log, a template, a database row or a stack trace.
 */
final readonly class DeepgramSettings
{
    public function __construct(
        public SecretValue $apiKey,
        public string $baseUrl,
        public string $model,
        public string $language,
        public bool $smartFormat,
        /**
         * Spoken numbers rendered as digits: "twenty five" becomes "25".
         *
         * Narrower than {@see $smartFormat}, which already formats numbers, dates and currency for
         * English — so the two overlap and this may well be redundant on an English deployment. It is
         * a separate switch because Deepgram treats it as one, and because "redundant" is a claim only
         * a real recording can settle.
         *
         * Formatting only. It changes the words in `transcript` and `punctuated_word`; it does not
         * move a timestamp, so alignment and diarization are untouched either way.
         */
        public bool $numerals,
        public int $timeoutSeconds,
        public DeepgramKeyterms $keyterms,
    ) {}

    /**
     * Whether Keyterm Prompting may be sent at all.
     *
     * Deepgram supports it on Nova-3 only. Sending it to another model is not a silent no-op — it is a
     * rejected request — so a deployment that has pinned an older model simply transcribes without
     * hints rather than failing every job.
     */
    public function supportsKeyterms(): bool
    {
        return str_starts_with($this->model, 'nova-3');
    }

    /**
     * Local configuration problems that stop this provider running.
     *
     * **Inspects settings only.** Nothing here opens a socket: a provider being unreachable is a
     * transcription failure for one job, not a configuration error, and conflating them would make an
     * outage look like an operator mistake.
     *
     * Note what is *not* here: the keyterm token budget. Deepgram owns that judgement — see
     * {@see DeepgramKeyterms}. Only the exactly-countable term limit is enforced.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->apiKey->isEmpty()) {
            $problems[] = 'DEEPGRAM_API_KEY is not set.';
        }

        if ($this->baseUrl === '') {
            $problems[] = 'DEEPGRAM_BASE_URL is empty.';
        } elseif (!str_starts_with($this->baseUrl, 'https://') && !str_starts_with($this->baseUrl, 'http://')) {
            $problems[] = 'DEEPGRAM_BASE_URL must be an absolute http(s) URL.';
        } elseif (str_contains($this->baseUrl, '?')) {
            // The engine appends its own query string. A base URL that already carries one would
            // produce two `?` separators and a request Deepgram cannot parse.
            $problems[] = 'DEEPGRAM_BASE_URL must not contain a query string.';
        }

        if ($this->model === '') {
            $problems[] = 'DEEPGRAM_MODEL is empty.';
        }

        if ($this->language === '') {
            $problems[] = 'DEEPGRAM_LANGUAGE is empty.';
        }

        $countProblem = $this->keyterms->countProblem();
        if ($countProblem !== null) {
            $problems[] = $countProblem;
        }

        return $problems;
    }

    public function isUsable(): bool
    {
        return $this->problems() === [];
    }

    /**
     * Advisory notes — never a reason to refuse a request.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        $budget = $this->keyterms->budgetWarning();
        if ($budget !== null) {
            $warnings[] = $budget;
        }

        if (!$this->keyterms->isEmpty() && !$this->supportsKeyterms()) {
            $warnings[] = 'DEEPGRAM_KEYTERMS is set but DEEPGRAM_MODEL is not a Nova-3 model, so Keyterm '
                . 'Prompting will not be sent. Transcription is unaffected.';
        }

        return $warnings;
    }
}
