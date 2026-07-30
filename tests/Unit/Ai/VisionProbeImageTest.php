<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\OpenAi\Console\VisionProbeImage;
use Codeception\Test\Unit;
use RuntimeException;

use function base64_decode;
use function getimagesizefromstring;
use function str_starts_with;
use function substr;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertNotFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The vision probe fixture must be a genuine, non-empty image whose declared MIME matches its bytes —
 * so the ping command exercises real image input and never sends a degenerate or malformed payload.
 */
final class VisionProbeImageTest extends Unit
{
    public function testDataUrlIsAValidPngDataUri(): void
    {
        $dataUrl = (new VisionProbeImage())->dataUrl();

        assertTrue(str_starts_with($dataUrl, 'data:image/png;base64,'));
    }

    public function testDecodedPayloadIsAGenuineNonTrivialImage(): void
    {
        $dataUrl = (new VisionProbeImage())->dataUrl();
        $base64 = substr($dataUrl, strlen('data:image/png;base64,'));

        $bytes = base64_decode($base64, true);
        assertNotFalse($bytes, 'payload must strictly base64-decode');

        $info = getimagesizefromstring((string) $bytes);
        assertNotFalse($info, 'payload must be a recognisable image');

        // Non-degenerate dimensions (the old 1×1 pixel was rejected by the API).
        assertGreaterThan(1, $info[0]);
        assertGreaterThan(1, $info[1]);

        // Declared MIME matches the actual detected format.
        assertSame('image/png', $info['mime']);
    }

    public function testMissingFixtureIsReportedAsAFixtureError(): void
    {
        $this->expectException(RuntimeException::class);

        (new VisionProbeImage('/does/not/exist/probe.png'))->dataUrl();
    }
}
