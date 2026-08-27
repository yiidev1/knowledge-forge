<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use finfo;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

use function basename;
use function in_array;
use function number_format;
use function pathinfo;
use function sprintf;
use function strtolower;

use const FILEINFO_MIME_TYPE;
use const PATHINFO_EXTENSION;
use const UPLOAD_ERR_CANT_WRITE;
use const UPLOAD_ERR_EXTENSION;
use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_NO_TMP_DIR;
use const UPLOAD_ERR_OK;
use const UPLOAD_ERR_PARTIAL;

/**
 * The upload gate.
 *
 * Returns a list of messages and never throws: a fixable upload is not an exceptional condition, it is
 * the form giving instructions. The first failure short-circuits, so the user is told one thing to fix
 * rather than a wall of consequences.
 *
 * The security-relevant rule is that **the browser is not consulted about what the file is**. Neither
 * the declared media type nor the filename decides anything: the extension allow-list pins the
 * container format the toolchain will be asked to open, and libmagic is run over the actual leading
 * bytes to reject anything that is not audio at all.
 */
final readonly class AudioUploadValidator
{
    /** @var non-empty-list<string> */
    public const EXTENSIONS = ['wav', 'mp3', 'm4a', 'ogg', 'webm'];

    private const SNIFF_BYTES = 4096;
    private const BYTES_PER_MEGABYTE = 1048576;

    /**
     * Deliberately wider than one entry per extension. libmagic versions disagree with each other —
     * a `.wav` is `audio/x-wav` on one box and `audio/wav` on the next, and `.m4a` is very often
     * reported as `video/mp4` because that is genuinely what the container is. The extension allow-list
     * above is what pins the format; this list only has to reject things that are plainly not audio.
     *
     * @var non-empty-list<string>
     */
    private const MIME_TYPES = [
        'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave',
        'audio/mpeg', 'audio/x-mpeg', 'audio/mp3',
        'audio/mp4', 'audio/m4a', 'audio/x-m4a', 'audio/aac', 'audio/x-hx-aac-adts', 'video/mp4',
        'audio/ogg', 'audio/x-ogg', 'application/ogg', 'video/ogg', 'audio/opus',
        'audio/webm', 'video/webm',
    ];

    public function __construct(
        private AudioToTextSettings $settings,
    ) {}

    /**
     * @return list<string> empty when the upload is acceptable
     */
    public function validate(?UploadedFileInterface $file): array
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return ['Choose an audio file first.'];
        }

        $uploadError = $this->uploadErrorMessage($file->getError());
        if ($uploadError !== null) {
            return [$uploadError];
        }

        $size = $file->getSize();
        if ($size === null || $size <= 0) {
            return ['That file is empty. Choose a recording with some audio in it.'];
        }

        if ($size > $this->settings->transcription->maxUploadBytes) {
            return [sprintf(
                'That file is %s, which is over the %s limit.',
                $this->megabytes($size),
                $this->settings->transcription->maxUploadLabel(),
            )];
        }

        $extension = $this->extensionOf($file->getClientFilename());
        if ($extension === null || !in_array($extension, self::EXTENSIONS, true)) {
            return [sprintf('Only %s files are supported.', $this->settings->transcription->allowedExtensionList())];
        }

        $mimeType = $this->sniff($file);
        if ($mimeType === null) {
            return ['That file could not be read. Please try selecting it again.'];
        }

        if (!in_array($mimeType, self::MIME_TYPES, true)) {
            return ['That file is not audio, whatever its name says. Choose a real recording.'];
        }

        return [];
    }

    private function uploadErrorMessage(int $error): ?string
    {
        return match ($error) {
            UPLOAD_ERR_OK => null,
            // PHP rejected it before a single byte reached us, so the reported size cannot be trusted.
            // Quote the limit rather than a figure we did not measure.
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                'That file is larger than the %s limit.',
                $this->settings->transcription->maxUploadLabel(),
            ),
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted before it finished. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE
                => 'The server could not store the upload. Ask an administrator to check the installation.',
            UPLOAD_ERR_EXTENSION
                => 'The upload was blocked by the server. Ask an administrator to check the installation.',
            default => 'The upload failed. Please try again.',
        };
    }

    /**
     * The extension of the *client's* filename, used only to decide whether we are willing to try.
     * The file the server writes is named by {@see QueuedAudioStorage}, never by this value —
     * `basename()` first, because a client is free to send `../../etc/passwd.wav`.
     */
    private function extensionOf(?string $clientFilename): ?string
    {
        if ($clientFilename === null || $clientFilename === '') {
            return null;
        }

        $extension = strtolower(pathinfo(basename($clientFilename), PATHINFO_EXTENSION));

        return $extension === '' ? null : $extension;
    }

    /**
     * Reads the real leading bytes and asks libmagic what they are.
     *
     * The stream is rewound afterwards, which is not optional: the very next thing that happens to this
     * upload is that it gets written to disk, and a stream left mid-file would silently truncate the
     * recording by 4 KB.
     */
    private function sniff(UploadedFileInterface $file): ?string
    {
        try {
            $stream = $file->getStream();
            if (!$stream->isReadable()) {
                return null;
            }

            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $header = $stream->read(self::SNIFF_BYTES);

            if ($stream->isSeekable()) {
                $stream->rewind();
            }
        } catch (Throwable) {
            return null;
        }

        if ($header === '') {
            return null;
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($header);

        return $detected === false || $detected === '' ? null : $detected;
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / self::BYTES_PER_MEGABYTE, 1, '.', '') . ' MB';
    }
}
