<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Domain\ValueObject\SecretValue;
use Codeception\Test\Unit;
use LogicException;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

/**
 * These tests exist because the API key leaking is the single worst failure this application can have.
 * Each one pins a specific route by which a secret normally escapes.
 */
final class SecretValueTest extends Unit
{
    private const KEY = 'sk-test-abcdefghijklmnop';

    public function testRevealReturnsThePlaintext(): void
    {
        assertSame(self::KEY, (new SecretValue(self::KEY))->reveal());
    }

    public function testStringConversionThrowsInsteadOfLeaking(): void
    {
        $secret = new SecretValue(self::KEY);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must not be converted to a string');

        // Interpolating a secret into a log line or a template is the most common leak. Making it a
        // hard error means that mistake cannot reach production silently.
        (static fn(string $s): string => $s)((string) $secret);
    }

    /**
     * var_export() ignores __debugInfo() and prints properties by reflection. It is the reason the
     * value is XOR-masked at rest rather than stored as plaintext.
     */
    public function testVarExportDoesNotContainTheSecret(): void
    {
        assertStringNotContainsString(self::KEY, var_export(new SecretValue(self::KEY), true));
    }

    /**
     * The same reflection route any dumper takes, including the one an error handler uses when
     * rendering a debug page.
     */
    public function testReflectionDoesNotExposeTheSecret(): void
    {
        $secret = new SecretValue(self::KEY);

        foreach ((new \ReflectionObject($secret))->getProperties() as $property) {
            $value = $property->getValue($secret);

            if (is_string($value)) {
                assertStringNotContainsString(self::KEY, $value);
            }
        }
    }

    public function testSerializationIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must not be serialized');

        serialize(new SecretValue(self::KEY));
    }

    public function testJsonEncodingDoesNotExposeTheSecret(): void
    {
        assertStringNotContainsString(
            self::KEY,
            (string) json_encode(['key' => new SecretValue(self::KEY)]),
        );
    }

    public function testPrintRDoesNotContainTheSecret(): void
    {
        $output = print_r(new SecretValue(self::KEY), true);

        assertStringNotContainsString(self::KEY, $output);
        assertStringContainsString('redacted', $output);
    }

    public function testVarDumpDoesNotContainTheSecret(): void
    {
        ob_start();
        var_dump(new SecretValue(self::KEY));
        $output = (string) ob_get_clean();

        assertStringNotContainsString(self::KEY, $output);
    }

    /**
     * An uncaught exception carrying a SecretValue argument prints its arguments in the trace. The
     * #[\SensitiveParameter] attribute on the constructor is what keeps the plaintext out of it.
     */
    public function testConstructorArgumentIsRedactedInStackTraces(): void
    {
        try {
            (static function (): void {
                new SecretValue(self::KEY);
                throw new LogicException('boom');
            })();
        } catch (LogicException $e) {
            assertStringNotContainsString(self::KEY, $e->getTraceAsString());
        }
    }

    public function testDigestIsStableAndDoesNotRevealTheSecret(): void
    {
        $secret = new SecretValue(self::KEY);

        assertSame($secret->digest(), (new SecretValue(self::KEY))->digest());
        assertStringNotContainsString(self::KEY, $secret->digest());
        assertStringContainsString('sha256:', $secret->digest());
    }

    public function testDifferentSecretsProduceDifferentDigests(): void
    {
        $this->assertNotSame(
            (new SecretValue('sk-one-aaaaaaaaaaaa'))->digest(),
            (new SecretValue('sk-two-bbbbbbbbbbbb'))->digest(),
        );
    }

    public function testEmptySecretIsReportedAsUnset(): void
    {
        $secret = new SecretValue('');

        assertTrue($secret->isEmpty());
        assertSame('<unset>', $secret->digest());
        assertSame(0, $secret->length());
    }

    public function testLengthIsExposedForDiagnostics(): void
    {
        $secret = new SecretValue(self::KEY);

        assertFalse($secret->isEmpty());
        assertSame(strlen(self::KEY), $secret->length());
    }
}
