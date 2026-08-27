<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\AudioUploadValidator;
use App\Tests\Support\AudioToTextSettingsFactory;
use HttpSoft\Message\Stream;
use HttpSoft\Message\UploadedFile;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

use function fopen;
use function fwrite;
use function pack;
use function rewind;
use function str_repeat;
use function strlen;

use const UPLOAD_ERR_CANT_WRITE;
use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;
use const UPLOAD_ERR_PARTIAL;

/**
 * Entirely in memory: no toolchain, no database, no fixture files.
 *
 * The WAV header below is hand-built rather than checked in as a binary, which keeps the repository free
 * of an opaque test asset and makes it obvious in the diff exactly what libmagic is being shown.
 */
final class AudioUploadValidatorTest extends TestCase
{
    private AudioUploadValidator $validator;

    protected function setUp(): void
    {
        // Named arguments and a literal settings object: the validator's behaviour must not depend on
        // whatever this machine's .env happens to say.
        $this->validator = new AudioUploadValidator(
            AudioToTextSettingsFactory::create(maxUploadBytes: 1024),
        );
    }

    public function testAValidWavIsAccepted(): void
    {
        $this->assertSame([], $this->validator->validate($this->upload($this->wavBytes(), 'call.wav')));
    }

    /**
     * The upload is written to disk immediately after this runs, so a stream left mid-file would silently
     * truncate the recording by the size of the sniff buffer.
     */
    public function testTheStreamIsLeftRewoundForTheNextReader(): void
    {
        $file = $this->upload($this->wavBytes(), 'call.wav');

        $this->validator->validate($file);

        $this->assertSame(0, $file->getStream()->tell());
    }

    public function testMissingUploadIsReported(): void
    {
        $this->assertSame(['Choose an audio file first.'], $this->validator->validate(null));
    }

    public function testAnEmptySubmissionIsReported(): void
    {
        $file = $this->upload('', 'nothing.wav', UPLOAD_ERR_NO_FILE);

        $this->assertSame(['Choose an audio file first.'], $this->validator->validate($file));
    }

    public function testAZeroByteFileIsRejected(): void
    {
        $errors = $this->validator->validate($this->upload('', 'empty.wav'));

        $this->assertSame(['That file is empty. Choose a recording with some audio in it.'], $errors);
    }

    public function testAnInterruptedUploadIsReported(): void
    {
        $file = $this->upload($this->wavBytes(), 'call.wav', UPLOAD_ERR_PARTIAL);

        $errors = $this->validator->validate($file);

        $this->assertSame(['The upload was interrupted before it finished. Please try again.'], $errors);
    }

    /**
     * PHP rejected the upload before any byte reached the application, so the reported size is not
     * trustworthy and only the limit is quoted.
     *
     * @dataProvider phpSizeErrorProvider
     */
    public function testAnUploadPhpAlreadyRejectedQuotesTheLimit(int $error): void
    {
        $errors = $this->validator->validate($this->upload($this->wavBytes(), 'call.wav', $error));

        $this->assertSame(['That file is larger than the 1 KB limit.'], $errors);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function phpSizeErrorProvider(): array
    {
        return [
            'php.ini limit' => [UPLOAD_ERR_INI_SIZE],
            'form MAX_FILE_SIZE' => [UPLOAD_ERR_FORM_SIZE],
        ];
    }

    public function testAServerSideStorageFailureIsReported(): void
    {
        $errors = $this->validator->validate($this->upload($this->wavBytes(), 'call.wav', UPLOAD_ERR_CANT_WRITE));

        $this->assertSame(
            ['The server could not store the upload. Ask an administrator to check the installation.'],
            $errors,
        );
    }

    public function testAnOversizedFileIsRejected(): void
    {
        $oversized = $this->wavBytes() . str_repeat("\0", 2048);

        $errors = $this->validator->validate($this->upload($oversized, 'long.wav'));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('which is over the 1 KB limit', $errors[0]);
    }

    public function testAnUnsupportedExtensionIsRejected(): void
    {
        $errors = $this->validator->validate($this->upload($this->wavBytes(), 'call.aiff'));

        $this->assertSame(['Only .wav, .mp3, .m4a, .ogg, .webm files are supported.'], $errors);
    }

    public function testAFileWithNoExtensionIsRejected(): void
    {
        $errors = $this->validator->validate($this->upload($this->wavBytes(), 'recording'));

        $this->assertSame(['Only .wav, .mp3, .m4a, .ogg, .webm files are supported.'], $errors);
    }

    /**
     * A traversing name is judged on its real extension, and the path portion never matters — the file the
     * server writes is named by the storage layer, not by anything the client sent.
     */
    public function testATraversingFilenameIsJudgedOnItsRealExtension(): void
    {
        $this->assertSame([], $this->validator->validate($this->upload($this->wavBytes(), '../../etc/passwd.wav')));
    }

    /**
     * The heart of it: the extension says audio, the bytes say otherwise, and the bytes win.
     *
     * @dataProvider disguisedFileProvider
     */
    public function testAFileThatIsNotAudioIsRejectedWhateverItIsCalled(string $contents, string $filename): void
    {
        $errors = $this->validator->validate($this->upload($contents, $filename));

        $this->assertSame(['That file is not audio, whatever its name says. Choose a real recording.'], $errors);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function disguisedFileProvider(): array
    {
        return [
            'text renamed .wav' => ["Just some plain text, definitely not audio.\n", 'fake.wav'],
            'php renamed .mp3' => ["<?php echo 'pwned'; ?>\n", 'shell.mp3'],
            'html renamed .ogg' => ["<!DOCTYPE html><html><body>hi</body></html>", 'page.ogg'],
        ];
    }

    /**
     * The browser is free to claim anything; nothing downstream consults it.
     */
    public function testTheDeclaredMediaTypeIsIgnored(): void
    {
        $file = new UploadedFile(
            $this->streamOf("not audio at all, whatever the header says"),
            42,
            UPLOAD_ERR_OK,
            'fake.wav',
            'audio/wav',
        );

        $errors = $this->validator->validate($file);

        $this->assertSame(['That file is not audio, whatever its name says. Choose a real recording.'], $errors);
    }

    private function upload(string $contents, string $filename, int $error = UPLOAD_ERR_OK): UploadedFileInterface
    {
        return new UploadedFile(
            $this->streamOf($contents),
            strlen($contents),
            $error,
            $filename,
            'application/octet-stream',
        );
    }

    private function streamOf(string $contents): Stream
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $contents);
        rewind($resource);

        return new Stream($resource);
    }

    /** A minimal but genuine RIFF/WAVE header — enough for libmagic to identify it as audio. */
    private function wavBytes(): string
    {
        return 'RIFF' . pack('V', 36) . 'WAVEfmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1)
            . pack('V', 8000) . pack('V', 16000) . pack('v', 2) . pack('v', 16) . 'data' . pack('V', 0);
    }
}
