<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\TranscriptFilename;
use App\AudioToText\Application\TranscriptText;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

/**
 * Download filenames and transcript text hygiene.
 *
 * The filename rules exist because this value reaches a `Content-Disposition` header. Folding to a
 * narrow character class means a quote, a semicolon or a newline cannot survive, so the header can
 * never be made to carry syntax of its own.
 */
final class TranscriptFilenameTest extends TestCase
{
    private const MOMENT = '2026-08-26 15:30:00';

    public function testTheDocumentedShapeIsProduced(): void
    {
        $this->assertSame(
            '21896109-transcript-20260826-153000.txt',
            TranscriptFilename::for('21896109.wav', $this->moment()),
        );
    }

    /** Three downloads of one job must not overwrite each other in the browser's downloads folder. */
    public function testEachPartGetsItsOwnName(): void
    {
        $this->assertSame('call-agent-20260826-153000.txt', TranscriptFilename::for('call.wav', $this->moment(), 'agent'));
        $this->assertSame(
            'call-customer-20260826-153000.txt',
            TranscriptFilename::for('call.wav', $this->moment(), 'customer'),
        );
    }

    /** An unrecognised part falls back to the complete transcript rather than inventing a name. */
    public function testAnUnknownPartFallsBackToTranscript(): void
    {
        $this->assertSame(
            'call-transcript-20260826-153000.txt',
            TranscriptFilename::for('call.wav', $this->moment(), 'nonsense'),
        );
    }

    public function testDirectoriesAreStripped(): void
    {
        $this->assertSame(
            'passwd-transcript-20260826-153000.txt',
            TranscriptFilename::for('../../etc/passwd.wav', $this->moment()),
        );
    }

    /**
     * @dataProvider hostileNameProvider
     */
    public function testHeaderSyntaxCannotSurvive(string $clientFilename): void
    {
        $filename = TranscriptFilename::for($clientFilename, $this->moment());

        $this->assertStringNotContainsString('"', $filename);
        $this->assertStringNotContainsString(';', $filename);
        $this->assertStringNotContainsString("\n", $filename);
        $this->assertStringNotContainsString('/', $filename);
        $this->assertTrue(TranscriptFilename::isSafe($filename));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileNameProvider(): array
    {
        return [
            'quote and header injection' => ['evil"; rm -rf /; x=".wav'],
            'newline' => ["line\nbreak.wav"],
            'traversal' => ['../../../etc/shadow.wav'],
            'unicode and punctuation' => ['réünion — call (1).wav'],
        ];
    }

    public function testAnUnusableNameFallsBack(): void
    {
        $this->assertSame('audio-transcript-20260826-153000.txt', TranscriptFilename::for('!!!.wav', $this->moment()));
        $this->assertSame('audio-transcript-20260826-153000.txt', TranscriptFilename::for(null, $this->moment()));
        $this->assertSame('audio-transcript-20260826-153000.txt', TranscriptFilename::for('', $this->moment()));
    }

    public function testAVeryLongNameIsCapped(): void
    {
        $filename = TranscriptFilename::for(str_repeat('a', 500) . '.wav', $this->moment());

        $this->assertLessThan(120, strlen($filename));
        $this->assertTrue(TranscriptFilename::isSafe($filename));
    }

    public function testTraversalIsRejectedByTheSafetyCheck(): void
    {
        $this->assertFalse(TranscriptFilename::isSafe('../evil.txt'));
        $this->assertFalse(TranscriptFilename::isSafe('evil.php'));
        $this->assertFalse(TranscriptFilename::isSafe('has space.txt'));
        $this->assertTrue(TranscriptFilename::isSafe('call-transcript-20260826-153000.txt'));
    }

    /**
     * The download header promises UTF-8; that promise has to be true of the bytes as well.
     */
    public function testInvalidUtf8IsSubstitutedRatherThanServed(): void
    {
        $cleaned = TranscriptText::toValidUtf8("valid \xB1\x31 tail");

        $this->assertTrue(mb_check_encoding($cleaned, 'UTF-8'));
        $this->assertStringContainsString('valid', $cleaned);
    }

    public function testValidUtf8IsUntouched(): void
    {
        $text = "Tori Guales 3, Apartment 1B — dos órdenes";

        $this->assertSame($text, TranscriptText::toValidUtf8($text));
    }

    /**
     * The list page shows previews, and the query never selects a whole transcript. Truncation must be
     * multibyte-safe so a cut never lands mid-character.
     */
    public function testPreviewsAreTruncatedSafely(): void
    {
        $this->assertNull(TranscriptText::preview(null, 20));
        $this->assertNull(TranscriptText::preview('   ', 20));
        $this->assertSame('Short one', TranscriptText::preview('Short one', 20));

        $long = TranscriptText::preview('Dos órdenes de alitas de pollo con tostones y dos latas de Coca-Cola', 20);

        $this->assertNotNull($long);
        $this->assertStringEndsWith('…', $long);
        $this->assertTrue(mb_check_encoding($long, 'UTF-8'));
        $this->assertLessThanOrEqual(21, mb_strlen($long));
    }

    /** A preview is a single line: newlines in a transcript must not break the table layout. */
    public function testPreviewsAreFlattenedToOneLine(): void
    {
        $preview = TranscriptText::preview("Agent line\nCustomer line", 100);

        $this->assertSame('Agent line Customer line', $preview);
    }

    private function moment(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::MOMENT, new DateTimeZone('UTC'));
    }
}
