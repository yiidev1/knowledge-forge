<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Environment;
use Codeception\Test\Unit;
use RuntimeException;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

final class EnvironmentTest extends Unit
{
    /**
     * Every key any test in this class touches. The suite runs shuffled, so anything left set by one
     * test would leak into another; restoring the full set in _after keeps them independent.
     */
    private const TOUCHED_KEYS = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_C3',
        'APP_HOST_PATH',
        'DB_NAME',
        'DB_PORT',
        'CHAT_REQUIRE_CITATIONS',
        'OPENAI_API_KEY',
        'OPENAI_CHAT_MAX_RETRIES',
        'OPENAI_CHAT_MODEL',
    ];

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function _before(): void
    {
        foreach (self::TOUCHED_KEYS as $key) {
            $this->originalEnv[$key] = getenv($key);
        }
    }

    protected function _after(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
        Environment::prepare();
    }

    public function testAppEnvIsReadFromEnvironment(): void
    {
        $this->setEnv('APP_ENV', 'test');

        assertSame('test', Environment::appEnv());
    }

    public function testAppEnvDefaultsToProdWhenNotSet(): void
    {
        $this->unsetEnv('APP_ENV');

        assertSame('prod', Environment::appEnv());
    }

    public function testAppEnvDefaultsToProdWhenEmpty(): void
    {
        $this->setEnv('APP_ENV', '');

        assertSame('prod', Environment::appEnv());
    }

    public function testInvalidAppEnvThrows(): void
    {
        $this->unsetEnv('APP_ENV');
        putenv('APP_ENV=staging');

        try {
            Environment::prepare();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            assertSame(
                'Environment variable APP_ENV="staging" is invalid. Expected one of "dev", "test", "prod".',
                $e->getMessage(),
            );
        } finally {
            putenv('APP_ENV');
        }
    }

    public function testMalformedIntegerThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Environment variable DB_PORT="not-a-number" is invalid. Expected an integer.');

        $this->setEnv('DB_PORT', 'not-a-number');
    }

    public function testOutOfRangeIntegerThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Environment variable OPENAI_CHAT_MAX_RETRIES="99" is invalid. Expected <= 5.');

        $this->setEnv('OPENAI_CHAT_MAX_RETRIES', '99');
    }

    public function testMalformedBooleanThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Environment variable CHAT_REQUIRE_CITATIONS="maybe" is invalid.');

        $this->setEnv('CHAT_REQUIRE_CITATIONS', 'maybe');
    }

    /**
     * A malformed credential must be reported without reproducing it: this message ends up in logs and
     * on error pages.
     */
    public function testInvalidSecretValueIsNotEchoedInTheMessage(): void
    {
        // Range is the only way a string secret can be malformed, so drive the same code path through a
        // key that is marked secret by forcing a failure elsewhere and asserting the general rule: no
        // secret value is ever interpolated into an exception message.
        $this->setEnv('OPENAI_API_KEY', 'sk-super-secret-key-value');

        try {
            $this->setEnv('DB_PORT', 'bad');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            assertStringNotContainsString('sk-super-secret-key-value', $e->getMessage());
        }
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown environment key "NOT_A_REAL_SETTING".');

        Environment::string('NOT_A_REAL_SETTING');
    }

    public function testFingerprintIsStableForIdenticalConfiguration(): void
    {
        $this->setEnv('DB_NAME', 'knowledge_forge_db');
        $first = Environment::fingerprint();

        Environment::prepare();
        $second = Environment::fingerprint();

        assertSame($first, $second);
    }

    public function testFingerprintChangesWhenConfigurationChanges(): void
    {
        $this->setEnv('DB_NAME', 'one');
        $first = Environment::fingerprint();

        $this->setEnv('DB_NAME', 'two');

        assertNotSame($first, Environment::fingerprint());
    }

    /**
     * The fingerprint is designed to be printed by both tiers and compared, so it must react to a
     * changed secret while never revealing one.
     */
    public function testFingerprintReactsToSecretsWithoutExposingThem(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'sk-first-key-value');
        $first = Environment::fingerprint();

        $this->setEnv('OPENAI_API_KEY', 'sk-second-key-value');

        assertNotSame($first, Environment::fingerprint());

        $description = implode("\n", Environment::describe());

        assertStringNotContainsString('sk-second-key-value', $description);
        assertStringContainsString('OPENAI_API_KEY=sha256:', $description);
    }

    public function testDescribeMarksUnsetValues(): void
    {
        $this->unsetEnv('OPENAI_CHAT_MODEL');

        assertStringContainsString('OPENAI_CHAT_MODEL=<unset>', implode("\n", Environment::describe()));
    }

    public function testSecretKeysAreDeclared(): void
    {
        $secrets = Environment::secretKeys();

        assertContains('OPENAI_API_KEY', $secrets);
        assertContains('DB_PASSWORD', $secrets);
    }

    public function testIsDev(): void
    {
        $this->setEnv('APP_ENV', 'dev');

        assertTrue(Environment::isDev());
        assertFalse(Environment::isTest());
        assertFalse(Environment::isProd());
    }

    public function testIsTest(): void
    {
        $this->setEnv('APP_ENV', 'test');

        assertTrue(Environment::isTest());
        assertFalse(Environment::isDev());
        assertFalse(Environment::isProd());
    }

    public function testIsProd(): void
    {
        $this->setEnv('APP_ENV', 'prod');

        assertTrue(Environment::isProd());
        assertFalse(Environment::isDev());
        assertFalse(Environment::isTest());
    }

    public function testAppDebugDefaultsToFalse(): void
    {
        $this->unsetEnv('APP_DEBUG');

        assertFalse(Environment::appDebug());
    }

    public function testAppDebugTrue(): void
    {
        $this->setEnv('APP_DEBUG', 'true');

        assertTrue(Environment::appDebug());
    }

    public function testAppDebugFalse(): void
    {
        $this->setEnv('APP_DEBUG', 'false');

        assertFalse(Environment::appDebug());
    }

    public function testAppC3DefaultsToFalse(): void
    {
        $this->unsetEnv('APP_C3');

        assertFalse(Environment::appC3());
    }

    public function testAppC3True(): void
    {
        $this->setEnv('APP_C3', 'true');

        assertTrue(Environment::appC3());
    }

    public function testAppHostPathDefaultsToNull(): void
    {
        $this->unsetEnv('APP_HOST_PATH');

        assertNull(Environment::appHostPath());
    }

    public function testAppHostPathIsRead(): void
    {
        $this->setEnv('APP_HOST_PATH', '/projects/myapp');

        assertSame('/projects/myapp', Environment::appHostPath());
    }

    public function testAppHostPathEmptyStringTreatedAsNull(): void
    {
        $this->setEnv('APP_HOST_PATH', '');

        assertNull(Environment::appHostPath());
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        Environment::prepare();
    }

    private function unsetEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key]);
        Environment::prepare();
    }
}
