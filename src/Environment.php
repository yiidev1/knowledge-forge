<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

use function array_key_exists;
use function in_array;
use function is_numeric;
use function sprintf;
use function trim;

/**
 * Single, validated entry point for every environment variable the application understands.
 *
 * Rules enforced here:
 *
 * - Values are read once, coerced to a declared type and range, and cached. Malformed input fails
 *   immediately and loudly instead of silently degrading to a default.
 * - Unknown keys are a programming error, not a runtime lookup miss.
 * - Secret values never appear in {@see fingerprint()}; they are represented by a short digest so two
 *   tiers can be compared for equality without either of them printing the secret.
 *
 * Business services never touch this class. It is read by `config/*` only, which builds typed readonly
 * parameter objects that are injected downstream.
 *
 * @psalm-type SpecEntry = array{
 *     type: 'bool'|'enum'|'float'|'int'|'string',
 *     default: bool|float|int|string|null,
 *     secret?: bool,
 *     min?: float|int,
 *     max?: float|int,
 *     values?: non-empty-list<string>,
 * }
 */
final class Environment
{
    public const DEV = 'dev';
    public const TEST = 'test';
    public const PROD = 'prod';

    public const ENVIRONMENTS = [
        self::DEV,
        self::TEST,
        self::PROD,
    ];

    /**
     * Declarative description of every supported variable.
     *
     * `default: null` on a string means "no usable default": the value stays an empty string until the
     * operator supplies one, and the parameter object that consumes it raises a configuration error.
     * That keeps `prepare()` safe to call in tests and CLI contexts that never touch the subsystem.
     *
     * @psalm-var array<string, SpecEntry>
     */
    private const SPEC = [
        // Application.
        'APP_ENV' => ['type' => 'enum', 'default' => self::PROD, 'values' => self::ENVIRONMENTS],
        'APP_DEBUG' => ['type' => 'bool', 'default' => false],
        'APP_C3' => ['type' => 'bool', 'default' => false],
        // URL prefix the application is served under when it does not own the domain root, e.g.
        // `/knowledge-forge` behind `https://example.com/knowledge-forge/`. Empty means domain root.
        'APP_BASE_PATH' => ['type' => 'string', 'default' => ''],
        'APP_HOST_PATH' => ['type' => 'string', 'default' => ''],

        // Authentication.
        'AUTH_MAX_LOGIN_ATTEMPTS' => ['type' => 'int', 'default' => 5, 'min' => 1, 'max' => 100],
        'AUTH_LOGIN_WINDOW_MINUTES' => ['type' => 'int', 'default' => 15, 'min' => 1, 'max' => 1440],
        'AUTH_LOGIN_LOCKOUT_MINUTES' => ['type' => 'int', 'default' => 15, 'min' => 1, 'max' => 1440],

        // Database.
        'DB_HOST' => ['type' => 'string', 'default' => '127.0.0.1'],
        'DB_PORT' => ['type' => 'int', 'default' => 3306, 'min' => 1, 'max' => 65535],
        // When set, the connection goes over this unix socket and DB_HOST/DB_PORT are ignored. Many
        // Ubuntu MySQL installs listen on a socket only, and a socket also avoids the loopback TCP
        // round trip entirely.
        'DB_SOCKET' => ['type' => 'string', 'default' => ''],
        'DB_NAME' => ['type' => 'string', 'default' => 'knowledge_forge_db'],
        'DB_USER' => ['type' => 'string', 'default' => ''],
        'DB_PASSWORD' => ['type' => 'string', 'default' => '', 'secret' => true],
        'DB_CHARSET' => ['type' => 'string', 'default' => 'utf8mb4'],

        // OpenAI credentials and models. Models have no default on purpose: an identifier the account
        // cannot access fails at runtime, and guessing one is worse than refusing to start.
        'OPENAI_API_KEY' => ['type' => 'string', 'default' => '', 'secret' => true],
        // Optional, and deliberately separate from OPENAI_API_KEY: organization reporting endpoints need
        // an admin-scoped key, and a project key must never be silently promoted to one. Absent is the
        // normal case — the usage dashboard then reports organization figures as unavailable and
        // everything else keeps working.
        'OPENAI_ADMIN_API_KEY' => ['type' => 'string', 'default' => '', 'secret' => true],
        'OPENAI_BASE_URL' => ['type' => 'string', 'default' => 'https://api.openai.com/v1'],
        'OPENAI_CHAT_MODEL' => ['type' => 'string', 'default' => ''],
        'OPENAI_VISION_MODEL' => ['type' => 'string', 'default' => ''],

        // OpenAI chat profile: the one synchronous call made from the web tier.
        'OPENAI_CHAT_CONNECT_TIMEOUT_SECONDS' => ['type' => 'int', 'default' => 5, 'min' => 1, 'max' => 120],
        'OPENAI_CHAT_TIMEOUT_SECONDS' => ['type' => 'int', 'default' => 45, 'min' => 1, 'max' => 300],
        'OPENAI_CHAT_MAX_RETRIES' => ['type' => 'int', 'default' => 1, 'min' => 0, 'max' => 5],
        'OPENAI_CHAT_RETRY_MAX_BACKOFF_SECONDS' => ['type' => 'int', 'default' => 2, 'min' => 0, 'max' => 60],

        // OpenAI worker profile: background ingestion driven by cron.
        'OPENAI_WORKER_CONNECT_TIMEOUT_SECONDS' => ['type' => 'int', 'default' => 10, 'min' => 1, 'max' => 120],
        'OPENAI_WORKER_TIMEOUT_SECONDS' => ['type' => 'int', 'default' => 120, 'min' => 1, 'max' => 900],
        'OPENAI_WORKER_MAX_RETRIES' => ['type' => 'int', 'default' => 3, 'min' => 0, 'max' => 10],
        'OPENAI_WORKER_RETRY_MAX_BACKOFF_SECONDS' => ['type' => 'int', 'default' => 60, 'min' => 0, 'max' => 600],

        // OpenAI retrieval.
        'OPENAI_FILE_SEARCH_MAX_RESULTS' => ['type' => 'int', 'default' => 8, 'min' => 1, 'max' => 50],
        'OPENAI_INDEX_POLL_INTERVAL_SECONDS' => ['type' => 'int', 'default' => 3, 'min' => 1, 'max' => 60],
        'OPENAI_INDEX_POLL_MAX_SECONDS' => ['type' => 'int', 'default' => 60, 'min' => 1, 'max' => 600],

        // Order58 Integration API. One shared Bearer token, one configurable base URL — change the token
        // here and every call updates. The base URL and token have no default (the client refuses to run
        // until both are set), so an unconfigured install simply skips sync rather than calling nowhere.
        'ORDER58_API_BASE_URL' => ['type' => 'string', 'default' => ''],
        'ORDER58_API_TOKEN' => ['type' => 'string', 'default' => '', 'secret' => true],
        'ORDER58_API_CONNECT_TIMEOUT_SECONDS' => ['type' => 'int', 'default' => 10, 'min' => 1, 'max' => 120],
        'ORDER58_API_TIMEOUT_SECONDS' => ['type' => 'int', 'default' => 30, 'min' => 1, 'max' => 300],
        'ORDER58_API_MAX_RETRIES' => ['type' => 'int', 'default' => 2, 'min' => 0, 'max' => 10],
        'ORDER58_API_RETRY_MAX_BACKOFF_SECONDS' => ['type' => 'int', 'default' => 30, 'min' => 0, 'max' => 600],
        'ORDER58_API_PAGE_SIZE' => ['type' => 'int', 'default' => 100, 'min' => 1, 'max' => 500],
        'ORDER58_SYNC_MAX_ATTEMPTS' => ['type' => 'int', 'default' => 3, 'min' => 1, 'max' => 20],
        'ORDER58_SYNC_PAGES_PER_RUN' => ['type' => 'int', 'default' => 1000, 'min' => 1, 'max' => 100000],
        // When false, Order58 store-profile documents stay in the DB/index but are hidden on the KB
        // documents list. Set true to show them again without code changes.
        'ORDER58_SHOW_STORE_PROFILE_DOCUMENTS' => ['type' => 'bool', 'default' => false],

        // Storage and upload limits.
        'KNOWLEDGE_STORAGE_PATH' => ['type' => 'string', 'default' => '@runtime/storage'],
        'MAX_UPLOAD_SIZE_MB' => ['type' => 'int', 'default' => 25, 'min' => 1, 'max' => 512],
        'MAX_IMAGE_UPLOAD_SIZE_MB' => ['type' => 'int', 'default' => 8, 'min' => 1, 'max' => 512],
        'MAX_DOCUMENTS_PER_KNOWLEDGE_BASE' => ['type' => 'int', 'default' => 200, 'min' => 1, 'max' => 100000],
        'IMAGE_MAX_WIDTH' => ['type' => 'int', 'default' => 12000, 'min' => 1, 'max' => 100000],
        'IMAGE_MAX_HEIGHT' => ['type' => 'int', 'default' => 12000, 'min' => 1, 'max' => 100000],

        // Background worker.
        'DOCUMENT_WORKER_BATCH_SIZE' => ['type' => 'int', 'default' => 1, 'min' => 1, 'max' => 100],
        'DOCUMENT_MAX_PROCESSING_ATTEMPTS' => ['type' => 'int', 'default' => 3, 'min' => 1, 'max' => 20],
        'DOCUMENT_PROCESSING_TIMEOUT_MINUTES' => ['type' => 'int', 'default' => 20, 'min' => 1, 'max' => 1440],
        'DOCUMENT_RETRY_BASE_SECONDS' => ['type' => 'int', 'default' => 60, 'min' => 1, 'max' => 86400],
        'DOCUMENT_WORKER_LOCK_PATH' => ['type' => 'string', 'default' => '@runtime/locks/worker.lock'],
        'AI_OPERATION_MAX_ATTEMPTS' => ['type' => 'int', 'default' => 5, 'min' => 1, 'max' => 20],
        'AI_OPERATION_RECONCILE_WINDOW_MINUTES' => ['type' => 'int', 'default' => 120, 'min' => 1, 'max' => 10080],

        // PDF ingestion policy.
        'PDF_MIN_TEXT_CHARS_PER_PAGE' => ['type' => 'int', 'default' => 100, 'min' => 1, 'max' => 100000],
        'PDF_TEXT_PROBE_MAX_BYTES' => ['type' => 'int', 'default' => 26214400, 'min' => 1024],
        'PDF_VISION_MAX_PAGES' => ['type' => 'int', 'default' => 50, 'min' => 1, 'max' => 1000],
        'PDF_VISION_MAX_BYTES' => ['type' => 'int', 'default' => 26214400, 'min' => 1024],

        // Chat.
        'CHAT_HISTORY_MESSAGE_LIMIT' => ['type' => 'int', 'default' => 10, 'min' => 0, 'max' => 200],
        'CHAT_HISTORY_CHAR_LIMIT' => ['type' => 'int', 'default' => 8000, 'min' => 0, 'max' => 500000],
        'CHAT_MAX_QUESTION_LENGTH' => ['type' => 'int', 'default' => 2000, 'min' => 1, 'max' => 100000],
        // How long a just-asked question stays editable, in minutes (server clock, from its created_at).
        'CHAT_EDIT_WINDOW_MINUTES' => ['type' => 'int', 'default' => 20, 'min' => 1, 'max' => 1440],
        'CHAT_MAX_OUTPUT_TOKENS' => ['type' => 'int', 'default' => 1200, 'min' => 1, 'max' => 100000],
        'CHAT_REQUIRE_CITATIONS' => ['type' => 'bool', 'default' => true],
        'CHAT_FORCE_FILE_SEARCH' => ['type' => 'bool', 'default' => true],
        'CHAT_MIN_CITATION_SCORE' => ['type' => 'float', 'default' => 0.0, 'min' => 0.0, 'max' => 1.0],
        'CHAT_FALLBACK_MESSAGE' => [
            'type' => 'string',
            'default' => 'I could not find enough information in the selected knowledge base to answer that question.',
        ],
        // A reasoning model bills its thinking against CHAT_MAX_OUTPUT_TOKENS. Left unbounded, a broad
        // question can spend the entire budget reasoning and return no citable answer at all.
        'CHAT_REASONING_EFFORT' => [
            'type' => 'enum',
            'default' => 'low',
            'values' => ['minimal', 'low', 'medium', 'high'],
        ],
        // Retrieval width for questions that ask for every match rather than the best one.
        'CHAT_EXHAUSTIVE_MAX_RESULTS' => ['type' => 'int', 'default' => 20, 'min' => 1, 'max' => 50],
        'CHAT_TRUNCATED_MESSAGE' => [
            'type' => 'string',
            'default' => 'The answer was cut short before it could be completed. '
                . 'Please ask for a narrower part of it — for example one section or one record at a time.',
        ],
    ];

    /** @var array<string, bool|float|int|string> */
    private static array $values = [];

    /**
     * Read, validate and cache every supported variable.
     *
     * Throws on malformed input (a non-numeric integer, an out-of-range value, an unknown `APP_ENV`).
     * It deliberately does *not* throw on a missing credential: required-ness is enforced by the
     * parameter object that consumes it, so a unit test or an unrelated console command is not forced
     * to carry a full production configuration.
     */
    public static function prepare(): void
    {
        self::$values = [];

        foreach (self::SPEC as $key => $spec) {
            self::$values[$key] = self::coerce($key, $spec, self::rawValue($key));
        }
    }

    /**
     * @return non-empty-string
     */
    public static function appEnv(): string
    {
        /** @var non-empty-string */
        return self::string('APP_ENV');
    }

    public static function isDev(): bool
    {
        return self::appEnv() === self::DEV;
    }

    public static function isTest(): bool
    {
        return self::appEnv() === self::TEST;
    }

    public static function isProd(): bool
    {
        return self::appEnv() === self::PROD;
    }

    /**
     * @return non-empty-string|null
     */
    public static function appHostPath(): ?string
    {
        $value = self::string('APP_HOST_PATH');

        return $value === '' ? null : $value;
    }

    /**
     * URL prefix the application is mounted on, normalised to `''` or `/segment[/segment...]`.
     *
     * An empty string means the application owns the domain root, which is the default and the shape
     * the routes are written in. When it is not empty the front end still passes the full path through,
     * so {@see \App\Shared\Web\Middleware\BasePathMiddleware} strips the prefix before routing and puts
     * it back on every generated URL. Normalising here — rather than at each call site — means
     * `/knowledge-forge`, `knowledge-forge/` and `/knowledge-forge/` all behave identically.
     */
    public static function appBasePath(): string
    {
        $value = trim(self::string('APP_BASE_PATH'), " \t\n\r\0\x0B/");

        return $value === '' ? '' : '/' . $value;
    }

    public static function appC3(): bool
    {
        return self::bool('APP_C3');
    }

    public static function appDebug(): bool
    {
        return self::bool('APP_DEBUG');
    }

    public static function string(string $key): string
    {
        $value = self::value($key, 'string');

        return (string) $value;
    }

    public static function int(string $key): int
    {
        $value = self::value($key, 'int');

        return (int) $value;
    }

    public static function float(string $key): float
    {
        $value = self::value($key, 'float');

        return (float) $value;
    }

    public static function bool(string $key): bool
    {
        return (bool) self::value($key, 'bool');
    }

    /**
     * Digest of the fully resolved configuration, safe to print and to compare across tiers.
     *
     * Secret values contribute a truncated SHA-256 of their content rather than the content itself, so
     * an operator can prove that PHP-FPM and the cron worker resolved *identical* configuration without
     * either process ever emitting a password or an API key.
     *
     * @return non-empty-string
     */
    public static function fingerprint(): string
    {
        /** @var non-empty-string */
        return hash('sha256', implode("\n", self::describe()));
    }

    /**
     * Human-readable, secret-free rendering of the resolved configuration, sorted by key.
     *
     * @return list<string>
     */
    public static function describe(): array
    {
        $lines = [];

        foreach (self::SPEC as $key => $spec) {
            $lines[] = $key . '=' . self::renderValue($key, $spec);
        }

        sort($lines);

        return $lines;
    }

    /**
     * Keys whose value is a credential. Used by reporting so it can label them without printing them.
     *
     * @return list<string>
     */
    public static function secretKeys(): array
    {
        $keys = [];

        foreach (self::SPEC as $key => $spec) {
            if ($spec['secret'] ?? false) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @psalm-param SpecEntry $spec
     */
    private static function renderValue(string $key, array $spec): string
    {
        $value = self::$values[$key] ?? '';

        if ($spec['secret'] ?? false) {
            $raw = (string) $value;

            return $raw === '' ? '<unset>' : 'sha256:' . substr(hash('sha256', $raw), 0, 8);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $rendered = (string) $value;

        return $rendered === '' ? '<unset>' : $rendered;
    }

    private static function value(string $key, string $expected): bool|float|int|string
    {
        if (!array_key_exists($key, self::SPEC)) {
            throw new RuntimeException(sprintf('Unknown environment key "%s".', $key));
        }

        if (self::SPEC[$key]['type'] !== $expected && !($expected === 'string' && self::SPEC[$key]['type'] === 'enum')) {
            throw new RuntimeException(
                sprintf(
                    'Environment key "%s" is declared as "%s" but was read as "%s".',
                    $key,
                    self::SPEC[$key]['type'],
                    $expected,
                ),
            );
        }

        if (!array_key_exists($key, self::$values)) {
            self::prepare();
        }

        return self::$values[$key];
    }

    /**
     * @psalm-param SpecEntry $spec
     */
    private static function coerce(string $key, array $spec, ?string $raw): bool|float|int|string
    {
        if ($raw === null || $raw === '') {
            /** @var bool|float|int|string */
            return $spec['default'] ?? '';
        }

        switch ($spec['type']) {
            case 'bool':
                $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($value === null) {
                    throw self::invalid($key, $raw, 'a boolean ("true", "false", "1", "0", "yes", "no")');
                }
                return $value;

            case 'int':
                if (!is_numeric($raw) || (string) (int) $raw !== trim($raw)) {
                    throw self::invalid($key, $raw, 'an integer');
                }
                return self::assertRange($key, (int) $raw, $spec);

            case 'float':
                if (!is_numeric($raw)) {
                    throw self::invalid($key, $raw, 'a number');
                }
                return self::assertRange($key, (float) $raw, $spec);

            case 'enum':
                $allowed = $spec['values'] ?? [];
                if (!in_array($raw, $allowed, true)) {
                    throw self::invalid($key, $raw, sprintf('one of "%s"', implode('", "', $allowed)));
                }
                return $raw;

            default:
                return $raw;
        }
    }

    /**
     * @psalm-param SpecEntry $spec
     */
    private static function assertRange(string $key, float|int $value, array $spec): float|int
    {
        $min = $spec['min'] ?? null;
        $max = $spec['max'] ?? null;

        if ($min !== null && $value < $min) {
            throw self::invalid($key, (string) $value, sprintf('>= %s', $min));
        }

        if ($max !== null && $value > $max) {
            throw self::invalid($key, (string) $value, sprintf('<= %s', $max));
        }

        return $value;
    }

    /**
     * The offending value is echoed only for non-secret keys; for a credential we say that it is
     * malformed without reproducing it in a log, a stack trace or an error page.
     */
    private static function invalid(string $key, string $raw, string $expectation): RuntimeException
    {
        $isSecret = self::SPEC[$key]['secret'] ?? false;

        return new RuntimeException(
            $isSecret
                ? sprintf('Environment variable %s is invalid. Expected %s.', $key, $expectation)
                : sprintf('Environment variable %s="%s" is invalid. Expected %s.', $key, $raw, $expectation),
        );
    }

    private static function rawValue(string $key): ?string
    {
        $value = getenv($key, true);
        if ($value !== false) {
            return $value;
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return isset($_ENV[$key]) ? (string) $_ENV[$key] : null;
    }
}
