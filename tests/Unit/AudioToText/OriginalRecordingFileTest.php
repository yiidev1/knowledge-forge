<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\QueuedAudioStorage;
use App\AudioToText\Domain\TranscriptionJob;
use App\AudioToText\Web\Job\JobPageGuard;
use App\AudioToText\Web\Job\Original\File\Action;
use App\Tests\Support\AudioToTextSettingsFactory;
use App\Tests\Support\Fake\AudioToText\OneJobRepository;
use App\Tests\Support\TranscriptionJobFactory;
use Codeception\Test\Unit;
use GuzzleHttp\Psr7\ServerRequest;
use HttpSoft\Message\ResponseFactory;
use Psr\Http\Message\ResponseInterface;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertStringStartsWith;

/**
 * The headers on a served recording, asserted where they can actually be seen.
 *
 * The Web suite proves the right *bytes* come back for a full request, a range and an over-long range,
 * but PhpBrowser exposes no response headers, so the three things that decide whether a browser will
 * play those bytes at all have until now been unasserted. They are not decoration:
 *
 * - `Content-Type` is what makes `<audio>` play the response instead of offering to download it. It is
 *   derived from the *stored* extension, never from the filename the uploader chose.
 * - `nosniff` is what stops a browser deciding for itself what a customer's upload is, which is the
 *   reason serving somebody's recording back is not an execution vector.
 * - `Accept-Ranges` is what makes Safari play at all: it opens an `<audio>` element with
 *   `Range: bytes=0-1` and will not play a source that answers 200 without advertising ranges.
 *
 * Driven by constructing the action directly, as {@see \App\Tests\Unit\Order58\RecordingDownloadTest}
 * does for the recording-API download, so no HTTP server and no database are involved.
 */
final class OriginalRecordingFileTest extends Unit
{
    /** Enough of a WAV to be a real stream, with NULs like real audio. */
    private const AUDIO = "RIFF\x24\0\0\0WAVEfmt \x10\0\0\0";

    private string $root = '';

    protected function _after(): void
    {
        if ($this->root === '') {
            return;
        }

        @unlink($this->root . '/recordings/' . TranscriptionJobFactory::mixedRecording()->publicId . '/source.wav');
        @rmdir($this->root . '/recordings/' . TranscriptionJobFactory::mixedRecording()->publicId);
        @rmdir($this->root . '/recordings');
        @rmdir($this->root);
        $this->root = '';
    }

    /** The full response carries the type, the sniff refusal and the range advertisement. */
    public function testAServedRecordingCarriesItsTypeAndCannotBeSniffed(): void
    {
        $response = $this->serve(TranscriptionJobFactory::mixedRecording());

        assertSame(200, $response->getStatusCode());
        assertSame('audio/wav', $response->getHeaderLine('Content-Type'));
        assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        // Inline, or the player is handed a download instead of a source.
        assertStringStartsWith('inline;', $response->getHeaderLine('Content-Disposition'));
        // A customer's call is never cached by anything in between.
        assertSame('private, max-age=0, must-revalidate', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * A 206 is typed exactly like the 200 it is a slice of.
     *
     * Worth its own assertion because the range branch builds its response separately: a `Content-Type`
     * present on the full download but missing from the partial one would still break playback, and
     * partial responses are how playback actually happens.
     */
    public function testARangedResponseIsTypedTheSameWay(): void
    {
        $response = $this->serve(TranscriptionJobFactory::mixedRecording(), 'bytes=0-3');

        assertSame(206, $response->getStatusCode());
        assertSame('audio/wav', $response->getHeaderLine('Content-Type'));
        assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    /**
     * Nothing in a 404 says which of the several reasons applied, or where anything lives.
     *
     * The job here exists and its column is set; only the file is absent. The response must be
     * indistinguishable from the one for an unknown id, and must not name the directory it looked in.
     */
    public function testAnAbsentFileIsAPlain404ThatLeaksNoPath(): void
    {
        $job = TranscriptionJobFactory::mixedRecording();
        $response = $this->serve($job, null, writeFile: false);

        assertSame(404, $response->getStatusCode());
        assertSame('', (string) $response->getBody());
        assertStringNotContainsString($this->root, (string) $response->getBody());
        assertStringNotContainsString('source.wav', (string) $response->getBody());
    }

    /** Asking under another recording's id finds nothing, whatever is on disk. */
    public function testAnotherRecordingsIdIsNotServed(): void
    {
        $job = TranscriptionJobFactory::mixedRecording();
        $response = $this->serve($job, null, askFor: 'ffffffffffffffffffffffffffffffff');

        assertSame(404, $response->getStatusCode());
    }

    /**
     * An extension with no entry in the allow-list is served as a download, not as its likely type.
     *
     * The guard that matters if a stored name ever holds something unexpected: the response is still
     * typed, but typed as bytes, and `nosniff` then keeps the browser from improving on that.
     */
    public function testAnUnknownExtensionIsServedAsOpaqueBytes(): void
    {
        $job = TranscriptionJobFactory::mixedRecording();
        $response = $this->serve($this->withRetainedName($job, 'source.bin'), null, storedName: 'source.bin');

        assertSame(200, $response->getStatusCode());
        assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    // ------------------------------------------------------------------------------------------ harness

    private function serve(
        TranscriptionJob $job,
        ?string $range = null,
        bool $writeFile = true,
        ?string $askFor = null,
        string $storedName = 'source.wav',
    ): ResponseInterface {
        $this->root = sys_get_temp_dir() . '/kf-original-file-' . $job->publicId;
        $directory = $this->root . '/recordings/' . $job->publicId;

        if ($writeFile) {
            if (!is_dir($directory)) {
                mkdir($directory, 0o775, true);
            }

            file_put_contents($directory . '/' . $storedName, self::AUDIO);
        }

        $settings = AudioToTextSettingsFactory::create(temporaryDirectory: $this->root);
        $action = new Action(
            new OneJobRepository($job),
            new QueuedAudioStorage($settings),
            new JobPageGuard(new ResponseFactory()),
            new ResponseFactory(),
        );

        $request = new ServerRequest('GET', '/audio-to-text/job/' . $job->publicId . '/original/file');

        if ($range !== null) {
            $request = $request->withHeader('Range', $range);
        }

        $response = $action($askFor ?? $job->publicId, $request);

        // Clean up here as well as in _after(), because a stored name other than the default is only
        // known inside this method.
        @unlink($directory . '/' . $storedName);

        return $response;
    }

    /** The same job with a different stored filename, since the factory always writes `source.wav`. */
    private function withRetainedName(TranscriptionJob $job, string $name): TranscriptionJob
    {
        return new TranscriptionJob(
            $job->id,
            $job->publicId,
            $job->uploadedByAdminId,
            $job->uploadedByUsername,
            $job->status,
            $job->stage,
            $job->originalFilename,
            $job->storedAudioPath,
            $name,
            $job->durationSeconds,
            $job->transcript,
            $job->detectedLanguage,
            $job->errorMessage,
            $job->agentText,
            $job->customerText,
            $job->speakerSegmentsJson,
            $job->speakerSeparationStatus,
            $job->speakerSeparationMethod,
            $job->speakerRoleConfidence,
            $job->createdAt,
            $job->startedAt,
            $job->completedAt,
            $job->expiresAt,
            $job->reviewedSegmentsJson,
            $job->reviewedAgentText,
            $job->reviewedCustomerText,
            $job->reviewedAt,
            $job->reviewedByUsername,
            $job->rolesConfirmedAt,
            $job->reviewCount,
            $job->conversationId,
            $job->sourceRole,
            null,
        );
    }
}
