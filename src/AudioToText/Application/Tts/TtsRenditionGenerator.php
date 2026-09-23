<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Tts;

use App\AudioToText\Application\AudioToTextSettings;
use App\AudioToText\Domain\Tts\AudioEncoderInterface;
use App\AudioToText\Domain\Tts\SpeechSynthesizerInterface;
use App\AudioToText\Domain\Tts\TtsException;
use App\AudioToText\Domain\Tts\TtsGenerationResult;
use App\AudioToText\Domain\Tts\TtsOutputType;
use App\AudioToText\Domain\Tts\TtsScript;
use Throwable;

use function bin2hex;
use function fclose;
use function filesize;
use function fopen;
use function fwrite;
use function mb_strlen;
use function random_bytes;
use function sprintf;
use function strlen;

/**
 * Turns a script into a published audio file. The step where money is actually spent.
 *
 * ## Order of operations, and why it is this order
 *
 * 1. **Readiness first, before a single request.** Both the provider's configuration and the ffmpeg
 *    encoder are checked up front. `libmp3lame` is a build-time option rather than a given, and finding
 *    it missing *after* synthesising a five-minute call means paying for audio that cannot be delivered.
 * 2. **Synthesise chunk by chunk, appending to one raw file.** Nothing is held in memory: a long call is
 *    tens of megabytes of PCM, and streaming it keeps the worker's footprint flat however long the
 *    recording is.
 * 3. **Encode once, to a work name.**
 * 4. **Rename into the published name**, which is atomic within a directory.
 *
 * The previous file — if this is a regeneration — is untouched by every one of those steps. It is still
 * on disk and still playable right up to the moment the new one is in place, which is what makes a
 * failed regeneration harmless. Deleting the superseded file is the caller's job, and happens only after
 * the database has been repointed. {@see TtsGenerationService}.
 *
 * ## What happens when it fails part way
 *
 * Work files are removed on every path, success or failure. Chunks already synthesised are lost, and
 * that loss is real: a forty-chunk call that fails on the thirty-ninth has been billed for thirty-nine
 * and produced nothing. Caching completed chunks across attempts would fix it and would mean holding
 * partial paid output across process boundaries with its own invalidation rules; the attempt cap bounds
 * the exposure instead, and the page shows the character count before anyone presses the button.
 */
final readonly class TtsRenditionGenerator
{
    public function __construct(
        private AudioToTextSettings $settings,
        private SpeechSynthesizerInterface $synthesizer,
        private AudioEncoderInterface $encoder,
        private GeneratedAudioStorage $storage,
        private TtsTextChunker $chunker,
    ) {}

    /**
     * Confirm this server can generate at all, before anything is claimed or charged.
     *
     * Separate from {@see generate()} so the worker can refuse a whole tick cleanly rather than failing
     * renditions one at a time for a condition that affects all of them equally.
     *
     * @throws TtsException naming what to fix
     */
    public function assertReady(): void
    {
        $this->synthesizer->assertReady();
        $this->encoder->assertReady($this->settings->tts->outputFormat);
    }

    /**
     * @param string $jobPublicId the recording this audio belongs to; also its directory
     * @param string $sourceHash  the transcript digest, which becomes part of the published filename so a
     *                            regeneration writes beside the old file rather than over it
     *
     * @throws TtsException on any failure, with the previous file left exactly as it was
     */
    public function generate(
        TtsScript $script,
        TtsOutputType $outputType,
        string $jobPublicId,
        string $sourceHash,
    ): TtsGenerationResult {
        if ($script->isEmpty()) {
            throw TtsException::nothingToSpeak($outputType);
        }

        $this->assertReady();

        $tts = $this->settings->tts;
        $token = bin2hex(random_bytes(8));
        $rawPath = $this->storage->beginWork($jobPublicId, $token, 'raw');

        try {
            [$rawBytes, $characters, $requests] = $this->synthesizeTo($rawPath, $script, $outputType);

            $encodedPath = $this->storage->beginWork($jobPublicId, $token, $tts->outputFormat->extension());
            $this->encoder->encode($rawPath, $encodedPath, $tts->sampleRate, $tts->outputFormat);

            $fileName = GeneratedAudioStorage::fileName($outputType, $sourceHash, $tts->outputFormat);
            $encodedBytes = filesize($encodedPath);

            $this->storage->publish($jobPublicId, $encodedPath, $fileName);

            return new TtsGenerationResult(
                $fileName,
                $encodedBytes === false ? 0 : $encodedBytes,
                $characters,
                $requests,
                PcmAudio::durationSeconds($rawBytes, $tts->sampleRate),
            );
        } finally {
            // Both paths. A failure that left its scratch behind would accumulate in a directory whose
            // job row is perfectly healthy, which the orphan sweep is not looking at.
            $this->storage->removeWorkFiles($jobPublicId);
        }
    }

    /**
     * Speak the script into one raw PCM file.
     *
     * @return array{int, int, int} bytes written, characters sent, requests made
     *
     * @throws TtsException
     */
    private function synthesizeTo(string $rawPath, TtsScript $script, TtsOutputType $outputType): array
    {
        $tts = $this->settings->tts;
        $handle = @fopen($rawPath, 'wb');

        if ($handle === false) {
            throw TtsException::writeFailed('the audio workspace could not be opened for writing');
        }

        // A breath between turns wherever there is more than one. That is every mixed rendition, and
        // also a single-side recording, which is one person taking several turns with real pauses
        // between them. A per-role file from a separate upload is one whole utterance and has no
        // boundary to place a gap at, so the condition below costs it nothing either way.
        $gap = $outputType === TtsOutputType::Mixed
            ? PcmAudio::silence($tts->gapMilliseconds, $tts->sampleRate)
            : '';

        $bytes = 0;
        $characters = 0;
        $requests = 0;

        try {
            foreach ($script->utterances as $index => $utterance) {
                if ($index > 0 && $gap !== '') {
                    $bytes += $this->write($handle, $gap);
                }

                foreach ($this->chunker->split($utterance->text, $tts->maxCharactersPerRequest) as $chunk) {
                    // Deliberately no silence between the chunks of one turn: those splits are a
                    // transport artefact of the provider's per-request ceiling, not something the speaker
                    // did, and a pause there would be audible in the middle of a sentence.
                    // The voice the script named, or the role's voice where it named none. A
                    // single-side recording carries diarizer roles that mean nothing about who spoke,
                    // so for those the script's own answer is the only one that may be used.
                    $model = $utterance->voice === null
                        ? $tts->modelFor($utterance->isAgent())
                        : $tts->modelForVoice($utterance->voice);

                    $audio = $this->synthesizer->synthesize($chunk, $model);

                    $bytes += $this->write($handle, $audio);
                    $characters += mb_strlen($chunk, 'UTF-8');
                    $requests++;
                }
            }
        } catch (Throwable $e) {
            fclose($handle);

            throw $e;
        }

        if (!fclose($handle)) {
            throw TtsException::writeFailed('the audio workspace could not be closed cleanly');
        }

        if ($bytes === 0) {
            throw TtsException::emptyAudio();
        }

        return [$bytes, $characters, $requests];
    }

    /**
     * @param resource $handle
     *
     * @throws TtsException on a short write — a full disk, typically, which must not pass silently into
     *                      a file that would then be published as complete
     */
    private function write($handle, string $bytes): int
    {
        $written = @fwrite($handle, $bytes);

        if ($written === false || $written !== strlen($bytes)) {
            throw TtsException::writeFailed(sprintf(
                'a short write occurred while assembling the audio (%s of %d bytes)',
                $written === false ? 'none' : (string) $written,
                strlen($bytes),
            ));
        }

        return $written;
    }
}
