<?php

declare(strict_types=1);

namespace App\Ai\OpenAi\Console;

use RuntimeException;

use function base64_decode;
use function base64_encode;
use function file_get_contents;
use function getimagesizefromstring;
use function in_array;
use function is_file;
use function is_readable;
use function sprintf;
use function strlen;

/**
 * Loads and validates the image fixture used by {@see OpenAiPingCommand} to exercise vision input.
 *
 * The fixture is a genuine, non-trivial PNG shipped in the repository — not an inline base64 literal,
 * which is easy to corrupt or leave degenerate. Every property the OpenAI Responses API cares about is
 * checked here before a request is ever sent (the file exists, is readable and non-empty, decodes,
 * is recognised as an image, and its detected MIME is one we declare), so a broken fixture surfaces as
 * a clear fixture error rather than being mistaken for the model rejecting image input.
 */
final readonly class VisionProbeImage
{
    private const PATH = __DIR__ . '/Resources/probe.png';

    /** @var list<string> */
    private const ALLOWED_MIME = ['image/png', 'image/jpeg'];

    public function __construct(
        private string $path = self::PATH,
    ) {}

    /**
     * @return string A `data:` URL whose declared MIME matches the fixture's actual detected format.
     *
     * @throws RuntimeException if the fixture is missing, unreadable, empty, undecodable, or not a
     *                          genuine PNG/JPEG image.
     */
    public function dataUrl(): string
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new RuntimeException(sprintf('Vision probe fixture is missing or unreadable at %s.', $this->path));
        }

        $bytes = file_get_contents($this->path);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Vision probe fixture is empty.');
        }

        // Round-trip base64 to guarantee the payload we send decodes back to exactly these bytes.
        $encoded = base64_encode($bytes);
        if (base64_decode($encoded, true) !== $bytes) {
            throw new RuntimeException('Vision probe fixture did not survive base64 round-trip.');
        }

        $info = getimagesizefromstring($bytes);
        if ($info === false) {
            throw new RuntimeException('Vision probe fixture is not a recognisable image.');
        }

        if ($info[0] < 1 || $info[1] < 1) {
            throw new RuntimeException('Vision probe fixture has zero dimensions.');
        }

        $mime = $info['mime'] ?? '';
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new RuntimeException(sprintf('Vision probe fixture MIME "%s" is not PNG or JPEG.', $mime));
        }

        // The declared MIME is the detected one, so they cannot disagree.
        return 'data:' . $mime . ';base64,' . $encoded;
    }

    /**
     * Byte length of the fixture, for diagnostics.
     */
    public function byteLength(): int
    {
        $bytes = is_file($this->path) ? file_get_contents($this->path) : false;

        return $bytes === false ? 0 : strlen($bytes);
    }
}
