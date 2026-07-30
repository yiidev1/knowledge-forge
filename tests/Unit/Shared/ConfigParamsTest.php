<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Ai\OpenAi\OpenAiCredentials;
use App\Ai\OpenAi\OpenAiHttpProfile;
use App\Shared\Domain\Exception\ConfigurationException;
use App\Shared\Infrastructure\Db\DbParams;
use App\Shared\Infrastructure\Storage\StoragePaths;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

final class ConfigParamsTest extends Unit
{
    private const PASSWORD = 'db-p4ssw0rd-value';
    private const API_KEY = 'sk-test-1234567890abcdef';

    public function testDbParamsBuildsEvenWhenIncomplete(): void
    {
        // Construction must not throw: kf:health has to build this object in order to report what is
        // missing. Enforcement lives in assertComplete(), which the connection factory calls.
        $params = new DbParams('127.0.0.1', 3306, 'knowledge_forge_db', '', '');

        assertFalse($params->isComplete());
        assertSame(['DB_USER'], $params->missingVariables());
    }

    public function testDbParamsAssertCompleteNamesTheMissingVariable(): void
    {
        $params = new DbParams('127.0.0.1', 3306, 'knowledge_forge_db', '', '');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('DB_USER');

        $params->assertComplete();
    }

    public function testDbParamsReportsEveryMissingVariable(): void
    {
        $params = new DbParams('', 3306, '', '', '');

        assertSame(['DB_HOST', 'DB_NAME', 'DB_USER'], $params->missingVariables());
    }

    public function testDbParamsIsCompleteWhenPopulated(): void
    {
        $params = new DbParams('127.0.0.1', 3306, 'knowledge_forge_db', 'kf', self::PASSWORD);

        assertTrue($params->isComplete());
        $params->assertComplete();
    }

    public function testDbParamsDsnPinsTheCharset(): void
    {
        $params = new DbParams('127.0.0.1', 3307, 'knowledge_forge_db', 'kf', self::PASSWORD);

        assertSame(
            'mysql:host=127.0.0.1;dbname=knowledge_forge_db;port=3307;charset=utf8mb4',
            (string) $params->dsn(),
        );
    }

    /**
     * Ubuntu's packaged MySQL often listens on a socket only — and does so exclusively when mysqld runs
     * with --skip-networking, in which case a TCP DSN simply cannot connect.
     */
    public function testDbParamsBuildsASocketDsnWhenConfigured(): void
    {
        $params = new DbParams(
            '127.0.0.1',
            3306,
            'knowledge_forge_db',
            'kf',
            self::PASSWORD,
            'utf8mb4',
            '/var/run/mysqld/mysqld.sock',
        );

        assertTrue($params->usesSocket());
        assertSame(
            'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=knowledge_forge_db;charset=utf8mb4',
            (string) $params->dsn(),
        );
        assertSame('mysql://kf@unix(/var/run/mysqld/mysqld.sock)/knowledge_forge_db', $params->describe());
    }

    public function testASocketAloneSatisfiesTheTransportRequirement(): void
    {
        $params = new DbParams('', 3306, 'knowledge_forge_db', 'kf', '', 'utf8mb4', '/tmp/mysql.sock');

        assertTrue($params->isComplete());
        assertSame([], $params->missingVariables());
    }

    public function testMissingBothTransportsIsReported(): void
    {
        $params = new DbParams('', 3306, 'knowledge_forge_db', 'kf', '');

        assertSame(['DB_HOST'], $params->missingVariables());
    }

    /**
     * `describe()` is printed by the health command and written to log records, so it must never carry
     * the password.
     */
    public function testDbParamsDescribeOmitsThePassword(): void
    {
        $params = new DbParams('127.0.0.1', 3306, 'knowledge_forge_db', 'kf', self::PASSWORD);

        assertSame('mysql://kf@127.0.0.1:3306/knowledge_forge_db', $params->describe());
        assertStringNotContainsString(self::PASSWORD, $params->describe());
    }

    public function testOpenAiCredentialsReportEveryMissingVariable(): void
    {
        $credentials = new OpenAiCredentials('', 'https://api.openai.com/v1', '', '');

        assertFalse($credentials->isComplete());
        assertSame(
            ['OPENAI_API_KEY', 'OPENAI_CHAT_MODEL', 'OPENAI_VISION_MODEL'],
            $credentials->missingVariables(),
        );
    }

    /**
     * Models are intentionally undefaulted: an id the account cannot reach must fail at configuration
     * time with a pointer to kf:openai:ping, not mid-answer.
     */
    public function testOpenAiCredentialsAssertCompleteMentionsThePingCommand(): void
    {
        $credentials = new OpenAiCredentials(self::API_KEY, 'https://api.openai.com/v1', '', '');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('kf:openai:ping');

        $credentials->assertComplete();
    }

    public function testOpenAiCredentialsAreCompleteWhenPopulated(): void
    {
        $credentials = new OpenAiCredentials(self::API_KEY, 'https://api.openai.com/v1/', 'model-a', 'model-b');

        assertTrue($credentials->isComplete());
        // Trailing slash is normalised away so URL building never produces a double slash.
        assertSame('https://api.openai.com/v1', $credentials->baseUrl);
    }

    public function testOpenAiCredentialsRejectANonHttpBaseUrl(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('OPENAI_BASE_URL');

        new OpenAiCredentials(self::API_KEY, 'ftp://example.com', 'model-a', 'model-b');
    }

    public function testOpenAiCredentialsDoNotExposeTheKey(): void
    {
        $credentials = new OpenAiCredentials(self::API_KEY, 'https://api.openai.com/v1', 'a', 'b');

        assertStringNotContainsString(self::API_KEY, print_r($credentials, true));
    }

    /**
     * The chat profile runs inside a web request, so its pessimistic bound has to stay under the web
     * server timeout. This is the calculation the health check compares against Nginx.
     */
    public function testChatProfileWorstCaseIsBounded(): void
    {
        $profile = new OpenAiHttpProfile('chat', 5, 45, 1, 2);

        // (45 + 5) attempts twice, plus one backoff of 2s.
        assertSame(102, $profile->worstCaseSeconds());
    }

    public function testProfileWithoutRetriesCostsOneAttempt(): void
    {
        $profile = new OpenAiHttpProfile('chat', 5, 45, 0, 2);

        assertSame(50, $profile->worstCaseSeconds());
    }

    public function testWorkerProfileMayBeFarMorePatient(): void
    {
        $worker = new OpenAiHttpProfile('worker', 10, 120, 3, 60);
        $chat = new OpenAiHttpProfile('chat', 5, 45, 1, 2);

        assertTrue($worker->worstCaseSeconds() > $chat->worstCaseSeconds());
    }

    public function testStoragePathsRejectRelativePaths(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('KNOWLEDGE_STORAGE_PATH');

        new StoragePaths('runtime/storage', '/var/lock/worker.lock', '/var/log');
    }

    public function testStoragePathsRejectAnEmptyLockPath(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('DOCUMENT_WORKER_LOCK_PATH');

        new StoragePaths('/srv/kf/storage', '', '/var/log');
    }

    public function testKnowledgeBaseDirectoryIsScopedById(): void
    {
        $paths = new StoragePaths('/srv/kf/storage/', '/srv/kf/locks/worker.lock', '/srv/kf/logs');

        assertSame('/srv/kf/storage/knowledge-bases/42', $paths->knowledgeBaseDirectory(42));
        assertSame('/srv/kf/locks', $paths->lockDirectory());
    }
}
