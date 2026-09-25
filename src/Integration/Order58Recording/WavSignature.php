<?php

declare(strict_types=1);

namespace App\Integration\Order58Recording;

use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function unpack;

/**
 * Whether the bytes that came back are actually a WAV file.
 *
 * ## Judged on the bytes, not on the Content-Type
 *
 * The live endpoint answers `application/octet-stream` for a perfectly good recording — the existing
 * tool's `BodyKind` documents that. So rejecting audio because the header said the wrong thing would
 * reject exactly the case this tool exists to test. The Content-Type is reported as a diagnostic and
 * never used as a verdict.
 *
 * A RIFF/WAVE header is 12 bytes: `RIFF`, a little-endian size, then `WAVE`. Checking both markers
 * rather than just `RIFF` matters, because `RIFF` alone also opens AVI and WebP.
 *
 * ## What it is careful not to claim
 *
 * A passing signature means "this begins like a WAV", not "this is playable audio". A truncated
 * download has a valid header and no sound. So the declared RIFF size is compared against what actually
 * arrived, which is what catches a body cut short mid-transfer — the failure most likely to be mistaken
 * for success.
 */
final readonly class WavSignature
{
    /** `RIFF` + 4-byte size + `WAVE`. Anything shorter cannot be inspected at all. */
    public const HEADER_BYTES = 12;

    /** A WAV with a real `fmt ` and `data` chunk cannot be smaller than this. */
    private const MINIMUM_PLAUSIBLE_BYTES = 44;

    private function __construct(
        public bool $isWav,
        public bool $looksTruncated,
        public ?int $declaredBytes,
        public string $summary,
    ) {}

    /**
     * @param string $sample     the first bytes of the body — 12 is enough, more allows no better answer
     * @param int    $totalBytes how many bytes actually arrived
     */
    public static function inspect(string $sample, int $totalBytes): self
    {
        if ($totalBytes === 0) {
            return new self(false, false, null, 'The response body was empty.');
        }

        if (strlen($sample) < self::HEADER_BYTES) {
            return new self(false, true, null, 'Too short to contain a WAV header.');
        }

        if (!str_starts_with($sample, 'RIFF')) {
            return new self(false, false, null, 'No RIFF marker — this is not a WAV file.');
        }

        if (substr($sample, 8, 4) !== 'WAVE') {
            // RIFF without WAVE is a different RIFF format entirely, such as AVI or WebP.
            return new self(false, false, null, 'RIFF present but no WAVE marker — a RIFF file, but not audio.');
        }

        // `RIFF` counts everything after its own 8-byte preamble, so the real file is 8 bytes larger.
        $declared = self::littleEndianSize(substr($sample, 4, 4));
        $expected = $declared === null ? null : $declared + 8;

        if ($totalBytes < self::MINIMUM_PLAUSIBLE_BYTES) {
            return new self(false, true, $expected, 'A valid WAV header, but far too few bytes to hold audio.');
        }

        if ($expected !== null && $totalBytes < $expected) {
            return new self(true, true, $expected, 'Valid WAV header, but the body is shorter than it declares — truncated.');
        }

        return new self(true, false, $expected, 'Valid WAV — RIFF/WAVE header present and the body is complete.');
    }

    /**
     * Whether the Content-Type is consistent with audio.
     *
     * Reported only. `application/octet-stream` is what the live endpoint actually sends, so this is
     * never the deciding factor — see the class docblock.
     */
    public static function contentTypeLooksLikeAudio(string $contentType): bool
    {
        $type = strtolower($contentType);

        return $type === ''
            || str_starts_with($type, 'audio/')
            || str_contains($type, 'octet-stream');
    }

    private static function littleEndianSize(string $fourBytes): ?int
    {
        /** @var array{1: int}|false $unpacked */
        $unpacked = unpack('V', $fourBytes);

        return $unpacked === false ? null : $unpacked[1];
    }
}
