<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\TranscriptionProvider;

use function is_array;
use function is_string;
use function sprintf;

/**
 * The two choices every audio upload carries, and the one place that decides what they mean.
 *
 * Which engine transcribes a recording, and whether clean audio is bought for it afterwards. Both are
 * posted by a browser, so both need reading and refusing rather than believing — and there is now more
 * than one form that posts them: the store page's upload dialog and the Manage Audio replacement.
 *
 * This exists so those two cannot drift. A second copy of the provider rule would not fail loudly; it
 * would accept a provider this server cannot run, and the recording would be uploaded, converted and
 * only then failed for a condition that was knowable at the click. A second copy of the checkbox rule
 * is worse, because the thing it decides is whether money is spent.
 *
 * ## Nothing here contacts a provider
 *
 * Readiness is {@see AudioToTextSettings::providerIsUsable()} — **local configuration only**. An upload
 * must not wait on a third party, and a provider outage must not be reported to an administrator as a
 * misconfiguration. That holds for rendering the field as much as for validating it.
 */
final readonly class UploadOptions
{
    public function __construct(
        private AudioToTextSettings $settings,
    ) {}

    /**
     * The provider for this submission, and why it was refused if it was.
     *
     * Three outcomes, and they are deliberately distinct:
     *
     *  - **No field posted.** An older form, or a browser that dropped it. The default stands; this is
     *    not an error.
     *  - **A value that is not a provider.** A tampered or stale form. Refused, because
     *    `fromStorage()` returns null rather than defaulting, and silently accepting Whisper for a
     *    request that asked for something else would report success for a choice nobody made.
     *  - **A real provider this server cannot run.** Refused *before* anything is stored or queued,
     *    with the reason.
     *
     * @param mixed $body the parsed request body, in whatever shape it arrived
     *
     * @return array{TranscriptionProvider, string|null}
     */
    public function provider(mixed $body, TranscriptionProvider $default): array
    {
        $value = is_array($body) ? $body['transcription_provider'] ?? null : null;
        $posted = is_string($value) ? $value : null;

        if ($posted === null || $posted === '') {
            return [$default, null];
        }

        $provider = TranscriptionProvider::fromStorage($posted);

        if ($provider === null) {
            return [$default, 'Choose one of the listed transcription providers.'];
        }

        if (!$this->settings->providerIsUsable($provider)) {
            return [
                $default,
                sprintf(
                    '%s is not configured on this server yet, so it cannot be used for this recording. '
                        . 'Choose another provider, or ask an administrator to finish setting it up.',
                    $provider->label(),
                ),
            ];
        }

        return [$provider, null];
    }

    /**
     * Whether the uploader ticked the AI-audio box.
     *
     * An unchecked checkbox posts nothing at all, so absence is the "no" — which is exactly what makes
     * a default of off reliable rather than something each form has to remember to say. Presence is
     * enough; the value is not inspected, because a browser that posts the field at all posted it
     * because the box was ticked.
     *
     * @param mixed $body the parsed request body, in whatever shape it arrived
     */
    public function wantsAiAudio(mixed $body): bool
    {
        return is_array($body) && ($body['generate_ai_audio'] ?? null) !== null;
    }

    /**
     * Whether paid audio can be generated on this server at all.
     *
     * Read by both forms to decide whether the checkbox is offered as usable, and by both actions to
     * make sure a request that ticked it anyway cannot record an intent this server cannot honour.
     */
    public function aiAudioIsUsable(): bool
    {
        return $this->settings->ttsIsUsable();
    }

    /**
     * Whether this machine can run one named provider.
     *
     * For a caller that already holds a provider rather than a posted string — a replacement falling
     * back to the engine the recording it replaces used, which may have been configured away since.
     */
    public function canRun(TranscriptionProvider $provider): bool
    {
        return $this->settings->providerIsUsable($provider);
    }

    /**
     * Which provider a field starts on when the preferred one cannot run.
     *
     * A select whose only `selected` option is `disabled` still submits that option, so preselecting a
     * provider this machine cannot run would hand the server a value it must refuse — a form that
     * fails when submitted unchanged. The first provider that *can* run is chosen instead. Nothing is
     * written: the global setting is a configuration decision and is not quietly corrected here.
     */
    public function preselected(TranscriptionProvider $preferred): TranscriptionProvider
    {
        if ($this->settings->providerIsUsable($preferred)) {
            return $preferred;
        }

        foreach (TranscriptionProvider::all() as $provider) {
            if ($this->settings->providerIsUsable($provider)) {
                return $provider;
            }
        }

        // Nothing on this server can transcribe. The preferred one is returned unchanged so the form
        // reports the real configuration rather than inventing a working one, and every submission is
        // refused by provider() above.
        return $preferred;
    }

    /**
     * Every provider, and whether this machine can run it, keyed by storage value.
     *
     * Every provider appears every time, including ones that cannot currently run: hiding the field
     * when only one works looked tidy and was wrong twice over — an administrator could not see which
     * engine their upload would use, and a broken install was indistinguishable from a
     * single-provider one.
     *
     * @return array<string, bool>
     */
    public function usability(): array
    {
        $usable = [];

        foreach (TranscriptionProvider::all() as $provider) {
            $usable[$provider->value] = $this->settings->providerIsUsable($provider);
        }

        return $usable;
    }
}
