<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

use App\Ai\OpenAi\OpenAiCredentials;
use App\Ai\OpenAi\OpenAiHttpProfile;
use App\Environment;
use App\Shared\Infrastructure\Db\DbConnectionFactory;
use App\Shared\Infrastructure\Db\DbParams;
use App\Shared\Infrastructure\Storage\StoragePaths;
use Throwable;

use function array_diff;
use function array_values;
use function basename;
use function count;
use function glob;
use function implode;
use function is_dir;
use function is_writable;
use function sort;
use function sprintf;
use function strrpos;
use function substr;

/**
 * Answers "is this installation correctly configured, and does it agree with the other tier?".
 *
 * Deliberately makes no network calls to OpenAI: verifying model access costs money and needs a live
 * key, so it lives in `kf:openai:ping` instead. This check must stay runnable on a half-configured box,
 * because reporting *why* it is half-configured is the whole point.
 */
final readonly class HealthChecker
{
    public function __construct(
        private DbParams $dbParams,
        private DbConnectionFactory $connectionFactory,
        private StoragePaths $storagePaths,
        private OpenAiCredentials $openAiCredentials,
        private OpenAiHttpProfile $chatProfile,
        private string $rootPath,
        private string $migrationsPath,
        /**
         * The `fastcgi_read_timeout` configured in the web server, in seconds. Used to check that the
         * synchronous chat budget actually fits underneath it; a chat call that outlives the web
         * server's patience surfaces to the user as a blank 504 rather than as an error page.
         */
        private int $webServerTimeoutSeconds = 90,
    ) {}

    public function run(): HealthReport
    {
        return new HealthReport(
            checks: [
                $this->checkEnvFile(),
                $this->checkDatabaseConfig(),
                $this->checkDatabaseConnection(),
                $this->checkMigrations(),
                $this->checkStorage(),
                $this->checkOpenAiConfig(),
                $this->checkChatTimeoutBudget(),
                $this->checkDebugFlag(),
            ],
            configFingerprint: Environment::fingerprint(),
            environment: Environment::appEnv(),
        );
    }

    private function checkEnvFile(): HealthCheck
    {
        $path = $this->rootPath . '/.env';

        if (!is_file($path)) {
            // Not fatal: a deployment may supply configuration through the FPM pool or systemd instead.
            // If it does, the same variables must also reach cron — compare fingerprints to confirm.
            return HealthCheck::warning(
                'env.file',
                'No .env file; configuration must come from the process environment.',
                'If you configure via FPM pool env[] or systemd, replicate every variable to the cron environment and compare the config fingerprint from both tiers.',
            );
        }

        $mode = fileperms($path);

        if ($mode === false) {
            return HealthCheck::warning('env.file', '.env exists but its permissions could not be read.');
        }

        $permissions = $mode & 0o777;

        if (($permissions & 0o004) !== 0) {
            return HealthCheck::failure(
                'env.file',
                '.env is world-readable, which exposes the API key and database password.',
                sprintf('Current mode %04o. Fix with: chmod 0640 %s', $permissions, $path),
            );
        }

        return HealthCheck::ok('env.file', '.env present with safe permissions.', sprintf('mode %04o', $permissions));
    }

    private function checkDatabaseConfig(): HealthCheck
    {
        if (!$this->dbParams->isComplete()) {
            return HealthCheck::failure(
                'database.config',
                'Database configuration is incomplete.',
                'Set: ' . implode(', ', $this->dbParams->missingVariables()),
            );
        }

        $detail = $this->dbParams->describe();

        if ($this->dbParams->password->isEmpty()) {
            return HealthCheck::warning(
                'database.config',
                'Database configured, but DB_PASSWORD is empty.',
                $detail,
            );
        }

        return HealthCheck::ok('database.config', 'Database configuration is complete.', $detail);
    }

    private function checkDatabaseConnection(): HealthCheck
    {
        if (!$this->dbParams->isComplete()) {
            return HealthCheck::failure(
                'database.connection',
                'Not attempted: database configuration is incomplete.',
            );
        }

        try {
            $connection = $this->connectionFactory->create();
            $version = $connection->getServerInfo()->getVersion();
            $connection->close();

            return HealthCheck::ok(
                'database.connection',
                'Connected.',
                sprintf('%s (server %s)', $this->dbParams->describe(), $version),
            );
        } catch (Throwable $e) {
            // The driver's message names the host, user and database but never the password, so it is
            // safe to surface and is by far the most useful thing an operator can be told here.
            return HealthCheck::failure(
                'database.connection',
                'Could not connect to the database.',
                $e->getMessage(),
            );
        }
    }

    /**
     * Compares the migration classes on disk against the ones recorded as applied.
     *
     * This reads the history table directly rather than going through `MigrationService`, because that
     * service requires a live connection at construction time — which would make the health command
     * impossible to start on exactly the misconfigured box it exists to diagnose. `./yii migrate:new`
     * remains the authoritative answer; this is a signal, not a substitute.
     */
    private function checkMigrations(): HealthCheck
    {
        if (!$this->dbParams->isComplete()) {
            return HealthCheck::warning('database.migrations', 'Not checked: database configuration is incomplete.');
        }

        $onDisk = $this->migrationClassesOnDisk();

        if ($onDisk === []) {
            return HealthCheck::warning('database.migrations', 'No migration classes found.', $this->migrationsPath);
        }

        try {
            $connection = $this->connectionFactory->create();

            // Absent history table means nothing has ever been applied; db-migration creates it on the
            // first `migrate:up`, so this is the expected state of a fresh database, not an error.
            if ($connection->getTableSchema('{{%migration}}') === null) {
                $connection->close();

                return HealthCheck::warning(
                    'database.migrations',
                    sprintf('Schema not initialised; %d migration(s) to apply.', count($onDisk)),
                    'Apply with: ./yii migrate:up',
                );
            }

            /** @var list<string> $applied */
            $applied = $connection->createQuery()->select('name')->from('{{%migration}}')->column();
            $connection->close();
        } catch (Throwable $e) {
            return HealthCheck::warning('database.migrations', 'Could not read migration history.', $e->getMessage());
        }

        $appliedShortNames = [];

        foreach ($applied as $name) {
            $position = strrpos($name, '\\');
            $appliedShortNames[] = $position === false ? $name : substr($name, $position + 1);
        }

        $pending = array_values(array_diff($onDisk, $appliedShortNames));

        if ($pending === []) {
            return HealthCheck::ok(
                'database.migrations',
                sprintf('Schema is up to date (%d applied).', count($onDisk)),
            );
        }

        return HealthCheck::warning(
            'database.migrations',
            sprintf('%d migration(s) not yet applied.', count($pending)),
            implode(', ', $pending) . '. Apply with: ./yii migrate:up',
        );
    }

    /**
     * @return list<string> Class names, which by convention equal the file basenames in src/Migration.
     */
    private function migrationClassesOnDisk(): array
    {
        $files = glob($this->migrationsPath . '/M*.php');

        if ($files === false) {
            return [];
        }

        $classes = [];

        foreach ($files as $file) {
            $classes[] = basename($file, '.php');
        }

        sort($classes);

        return $classes;
    }

    private function checkStorage(): HealthCheck
    {
        $problems = [];

        $required = [
            'document storage' => $this->storagePaths->documentRoot,
            'worker lock directory' => $this->storagePaths->lockDirectory(),
            'log directory' => $this->storagePaths->logDirectory,
        ];

        foreach ($required as $label => $path) {
            if (!is_dir($path)) {
                $problems[] = sprintf('%s missing: %s', $label, $path);
                continue;
            }

            // Uploads are written by PHP-FPM and read back by the cron worker. If either identity
            // cannot write here, documents queue up and never process.
            if (!is_writable($path)) {
                $problems[] = sprintf('%s not writable by %s: %s', $label, $this->currentUser(), $path);
            }
        }

        if ($problems !== []) {
            return HealthCheck::failure(
                'storage',
                'Storage directories are not usable.',
                implode('; ', $problems)
                . '. Create them with: install -d -o <deploy_user> -g www-data -m 2775 <path>',
            );
        }

        return HealthCheck::ok(
            'storage',
            'Storage directories exist and are writable.',
            sprintf('as %s: %s', $this->currentUser(), $this->storagePaths->documentRoot),
        );
    }

    private function checkOpenAiConfig(): HealthCheck
    {
        if (!$this->openAiCredentials->isComplete()) {
            return HealthCheck::warning(
                'openai.config',
                'OpenAI is not fully configured; ingestion and chat are unavailable.',
                'Set: ' . implode(', ', $this->openAiCredentials->missingVariables())
                . '. Then verify model access with: ./yii kf:openai:ping',
            );
        }

        return HealthCheck::ok(
            'openai.config',
            'OpenAI credentials and models are set.',
            sprintf(
                'key %s, chat "%s", vision "%s". Model access is not checked here — run ./yii kf:openai:ping.',
                $this->openAiCredentials->apiKey->digest(),
                $this->openAiCredentials->chatModel,
                $this->openAiCredentials->visionModel,
            ),
        );
    }

    private function checkChatTimeoutBudget(): HealthCheck
    {
        $worstCase = $this->chatProfile->worstCaseSeconds();

        if ($worstCase >= $this->webServerTimeoutSeconds) {
            return HealthCheck::warning(
                'chat.timeout_budget',
                'The chat retry budget can outlive the web server timeout.',
                sprintf(
                    'Worst case %ds vs fastcgi_read_timeout %ds. Lower OPENAI_CHAT_TIMEOUT_SECONDS or OPENAI_CHAT_MAX_RETRIES, or raise the web server timeout.',
                    $worstCase,
                    $this->webServerTimeoutSeconds,
                ),
            );
        }

        return HealthCheck::ok(
            'chat.timeout_budget',
            'Chat retry budget fits within the web server timeout.',
            sprintf('worst case %ds of %ds', $worstCase, $this->webServerTimeoutSeconds),
        );
    }

    private function checkDebugFlag(): HealthCheck
    {
        if (Environment::isProd() && Environment::appDebug()) {
            return HealthCheck::failure(
                'app.debug',
                'APP_DEBUG is enabled in production.',
                'Debug output renders stack traces and request state to the browser. Set APP_DEBUG=false.',
            );
        }

        return HealthCheck::ok(
            'app.debug',
            sprintf('APP_ENV=%s, APP_DEBUG=%s.', Environment::appEnv(), Environment::appDebug() ? 'true' : 'false'),
        );
    }

    /**
     * Which OS user this process runs as. Printed because the most common storage failure on a shared
     * server is PHP-FPM and cron running as different users.
     */
    private function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());

            if ($info !== false) {
                return $info['name'];
            }
        }

        return get_current_user() ?: 'unknown';
    }
}
