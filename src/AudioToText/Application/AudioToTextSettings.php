<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Application\Settings\DeepgramSettings;
use App\AudioToText\Application\Settings\DiarizationSettings;
use App\AudioToText\Application\Settings\TranscriptionSettings;
use App\AudioToText\Application\Settings\TtsSettings;
use App\AudioToText\Application\Settings\WorkerSettings;
use App\AudioToText\Domain\TranscriptionProvider;

use function implode;
use function is_dir;
use function is_executable;
use function is_file;
use function is_readable;
use function sprintf;

/**
 * ============================================================================================
 *  TO CHANGE AUDIO-TO-TEXT CONFIGURATION, CHANGE IT IN `.env`.
 *
 *  Every value below has exactly one authoritative definition, and it is the `SPEC` entry in
 *  {@see \App\Environment}: that is where the default, the type and the valid range live. `.env`
 *  overrides it for this deployment. Two files sit between them and hold no opinions of their own —
 *  `config/common/params.php` reads the variables, `config/common/di/audio-to-text.php` assembles
 *  this object. Neither invents a default, and no class in this module reads an environment
 *  variable directly.
 *
 *  Adding a setting is therefore three mechanical edits and no judgement calls:
 *    1. `src/Environment.php`            — the default, type and range  (the source of truth)
 *    2. `config/common/params.php`       — read it
 *    3. `config/common/di/audio-to-text.php` — pass it here
 * ============================================================================================
 *
 * This is the **only** settings type any Audio-to-Text service depends on. The three groups below are
 * for readability, never for injection: a service that needs a diarization value receives this object
 * and reads `->diarization`. That is what keeps a new setting from rippling through constructors.
 */
final readonly class AudioToTextSettings
{
    public function __construct(
        public TranscriptionSettings $transcription,
        public WorkerSettings $worker,
        public DiarizationSettings $diarization,
        public DeepgramSettings $deepgram,
        /**
         * Text-to-speech, for generating clean training audio from a transcript that already exists.
         *
         * Carried here so services take one settings object, exactly like the four above — but note what
         * is **not** done with it: {@see problems()} never looks at it, and neither does anything in the
         * transcription path. See {@see ttsIsUsable()}.
         */
        public TtsSettings $tts,
    ) {}

    /**
     * The environment every Audio-to-Text child process is given.
     *
     * Defined here, once, and derived from `threads` — so the CPU budget is a single configured number
     * rather than a literal repeated across ffmpeg, whisper and the diarizer. `proc_open()` replaces the
     * environment wholesale, so this list has to be complete, and being complete is also the point: no
     * inherited PHP-FPM environment reaches a child.
     *
     * The `*_NUM_THREADS` family exists because a numeric library will otherwise size its own pool from
     * the core count. Pinning them is what keeps "one thread" true below the level ffmpeg's `-threads`
     * and whisper's `-t` can reach.
     *
     * @return array<string, string>
     */
    public function childProcessEnvironment(): array
    {
        $threads = (string) max(1, $this->transcription->threads);

        return [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
            'OMP_NUM_THREADS' => $threads,
            'OPENBLAS_NUM_THREADS' => $threads,
            'MKL_NUM_THREADS' => $threads,
            'NUMEXPR_NUM_THREADS' => $threads,
            'VECLIB_MAXIMUM_THREADS' => $threads,
            'ORT_DISABLE_ALL_THREAD_POOL_SPINNING' => '1',
        ];
    }

    /**
     * Checks the configuration makes sense, before any work is claimed.
     *
     * Run once at worker startup. The point is to turn a misconfiguration into a sentence naming the
     * variable to fix, rather than into a job that fails ten minutes later with a message about a
     * missing file — or worse, a queue that silently never moves.
     *
     * **Diarization is only checked when it is enabled.** Requiring models nobody has installed would
     * make the default configuration fail its own validation, which is precisely backwards.
     *
     * ## No transcription provider is checked here, and that is the point
     *
     * A non-empty result stops the worker before it claims anything, so anything listed here takes the
     * whole queue down. That is correct for ffmpeg, which every job needs, and wrong for an engine,
     * which only its own jobs need: this method used to check the whisper binary and model, which meant
     * an uninstalled whisper model froze Deepgram jobs too, for no reason.
     *
     * Provider readiness now lives with each provider — {@see Settings\TranscriptionSettings::whisperProblems()}
     * and {@see Settings\DeepgramSettings::problems()} — is checked once per job by the engine's
     * `assertReady()`, and fails that job alone while the worker carries on. An unready provider is
     * surfaced here only as a {@see warnings()} line, which never stops anything.
     *
     * @return list<string> problems, most serious first; empty when the configuration is usable
     */
    public function problems(): array
    {
        $problems = [];

        // ffmpeg and ffprobe only. **Every** provider needs both — ffmpeg normalises the WAV that the
        // diarizer also consumes, and ffprobe measures duration at enqueue — so neither belongs to one
        // engine, and neither can be deferred to one.
        //
        // The whisper binary and model are deliberately absent: see the method docblock.
        foreach ([
            'FFMPEG_BINARY' => $this->transcription->ffmpegBinary,
            'FFPROBE_BINARY' => $this->transcription->ffprobeBinary,
        ] as $variable => $path) {
            if (!is_file($path) || !is_executable($path)) {
                $problems[] = sprintf('%s: "%s" is not an executable file.', $variable, $path);
            }
        }

        if ($this->transcription->temporaryDirectory === '') {
            $problems[] = 'AUDIO_TRANSCRIPTION_TEMP_DIR resolved to an empty path.';
        }

        // A recording at the duration cap takes roughly 1.3x its length to transcribe at one thread, so
        // a timeout below that guarantees every long job dies on the clock. Caught here rather than
        // discovered by the first user who uploads a two-minute call.
        $needed = (int) ($this->transcription->maxDurationSeconds * 1.5);
        if ($this->transcription->timeoutSeconds < $needed) {
            $problems[] = sprintf(
                'AUDIO_TRANSCRIPTION_TIMEOUT (%ds) is too low for AUDIO_TRANSCRIPTION_MAX_DURATION (%ds): '
                . 'transcription runs at roughly 1.3x real time, so allow at least %ds.',
                $this->transcription->timeoutSeconds,
                $this->transcription->maxDurationSeconds,
                $needed,
            );
        }

        // Fail-closed coordination means an unreadable lock defers every tick forever. The usual cause
        // is mundane and invisible — another project's lock directory is 0750 and owned by its own user
        // — so it is named explicitly rather than left to be inferred from a stalled queue.
        foreach ($this->worker->foreignLockPaths() as $path) {
            if (!is_readable($path)) {
                $problems[] = sprintf(
                    'AUDIO_WORKER_FOREIGN_LOCKS: "%s" is missing or unreadable, so every tick will be '
                    . 'deferred. Run the worker as the user that owns it (normally www-data), or clear '
                    . 'the setting to opt out of cross-project coordination.',
                    $path,
                );
            }
        }

        if (!$this->diarization->enabled) {
            return $problems;
        }

        if (!is_file($this->diarization->binary) || !is_executable($this->diarization->binary)) {
            $problems[] = sprintf(
                'AUDIO_DIARIZATION_BINARY: "%s" is not an executable file. Set AUDIO_DIARIZATION_ENABLED=false '
                . 'until the diarization toolchain is installed.',
                $this->diarization->binary,
            );
        }

        foreach ([
            'AUDIO_DIARIZATION_SEGMENTATION_MODEL' => $this->diarization->segmentationModel,
            'AUDIO_DIARIZATION_EMBEDDING_MODEL' => $this->diarization->embeddingModel,
        ] as $variable => $path) {
            if (!is_readable($path)) {
                $problems[] = sprintf('%s: "%s" is not a readable file.', $variable, $path);
            }
        }

        return $problems;
    }

    /**
     * Problems that are not fatal but are worth saying out loud once, at startup.
     *
     * This is where an unready transcription provider surfaces. Saying it here rather than in
     * {@see problems()} is the whole trade: the operator sees the problem on every tick, and the queue
     * keeps draining the jobs that do not need that provider.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        foreach ($this->providerProblems() as $label => $problems) {
            $warnings[] = sprintf(
                '%s is not ready, so jobs queued for it will fail (other providers are unaffected): %s',
                $label,
                implode(' ', $problems),
            );
        }

        foreach ($this->deepgram->warnings() as $advisory) {
            $warnings[] = $advisory;
        }

        if ($this->transcription->threads > 1) {
            $warnings[] = sprintf(
                'AUDIO_TRANSCRIPTION_THREADS is %d. The pipeline is designed to occupy exactly one CPU '
                . 'core; a higher value lets a single transcription saturate this machine.',
                $this->transcription->threads,
            );
        }

        if ($this->worker->foreignLockPaths() === []) {
            $warnings[] = 'AUDIO_WORKER_FOREIGN_LOCKS is empty, so nothing stops another project on this '
                . 'machine from running its own transcription at the same time as this one.';
        }

        if (!$this->diarization->enabled) {
            $warnings[] = 'Speaker separation is disabled. Transcription is unaffected; jobs will record '
                . 'NOT_SUPPORTED for the customer/agent split.';
        }

        if (!is_dir($this->transcription->jobsDirectory())) {
            $warnings[] = 'The job directory does not exist yet; it will be created on first use.';
        }

        return $warnings;
    }

    /**
     * Every provider that cannot run here, with the reasons.
     *
     * The one place that walks all providers. Keeping the loop here — rather than a pair of `if`s —
     * means adding a third engine adds a case to {@see TranscriptionProvider} and a branch below, and
     * the startup warning, the readiness check and any future admin page all learn about it at once.
     *
     * Configuration inspection only. Nothing here opens a socket or starts a process, so it is safe to
     * call on every worker tick and inside a web request alike.
     *
     * @return array<string, list<string>> keyed by provider label; a ready provider is absent
     */
    public function providerProblems(): array
    {
        $byProvider = [];

        foreach (TranscriptionProvider::all() as $provider) {
            $problems = match ($provider) {
                TranscriptionProvider::Whisper => $this->transcription->whisperProblems(),
                TranscriptionProvider::Deepgram => $this->deepgram->problems(),
            };

            if ($problems !== []) {
                $byProvider[$provider->label()] = $problems;
            }
        }

        return $byProvider;
    }

    /**
     * Whether a provider may be offered for a new upload.
     *
     * Asked by the upload page before enqueue, so a recording is never queued for an engine that cannot
     * possibly run it. **Local configuration only** — this must never be allowed to become a health
     * check against a provider's API: that would put a network round trip in an upload request and make
     * a provider outage look like a misconfiguration.
     */
    public function providerIsUsable(TranscriptionProvider $provider): bool
    {
        return match ($provider) {
            TranscriptionProvider::Whisper => $this->transcription->whisperIsUsable(),
            TranscriptionProvider::Deepgram => $this->deepgram->isUsable(),
        };
    }

    /**
     * Whether AI training audio may be offered at all.
     *
     * Asked by the page that shows the button and by the text-to-speech worker at startup. **Local
     * configuration only**, for the same reason {@see providerIsUsable()} gives: a page render must not
     * make a network call, and a provider outage must not be reported to an administrator as something
     * they should go and fix in `.env`.
     *
     * ## Why this is not part of {@see problems()}
     *
     * That method gates the transcription worker — a non-empty result and it exits before claiming
     * anything. Text-to-speech is optional and entirely separate from turning audio into text, so a
     * mistyped voice name has no business being able to stop every transcription on the machine. This is
     * the same lesson `problems()` already records about the Whisper binary, applied one step further
     * out.
     */
    public function ttsIsUsable(): bool
    {
        return $this->tts->isUsable();
    }
}
