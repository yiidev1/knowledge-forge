<?php

declare(strict_types=1);

namespace App\Order58\Web\TestRecordingApis;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

use function is_array;
use function is_scalar;
use function json_decode;
use function json_encode;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Manual probe of the two external Order58 recording endpoints (GET /admin/order58/test-recording-apis).
 *
 * **This is a diagnostic page and nothing else.** It reads no database, writes no database, enqueues no
 * job, saves no file, and shares no code with the Order58 sync client or with Audio-to-Text. It makes at
 * most one outbound GET per page load and prints what came back.
 *
 * The two APIs are independent by construction. Each form posts its own `api` value, and the action runs
 * exactly the one named — so submitting Latest Calls cannot trigger a recording fetch, and vice versa. A
 * bare URL with no `api` runs neither and simply shows both forms filled with the defaults below.
 *
 * Two deliberate departures from how the rest of the app behaves, both scoped to this directory:
 *
 *  - **Errors are shown, not swallowed.** A non-2xx response is rendered with its body intact (the
 *    provider's `403 Forbidden - IP not authorized: …` is the point of the page, not something to replace
 *    with a friendly message), and a transport failure — DNS, TLS, timeout, refused connection — is caught
 *    here and printed with its class, message and trace. Catching it locally is what keeps the change
 *    isolated: the global error handler is untouched, so every other route still fails the way it did.
 *  - **No credential is sent.** Both endpoints are gated by an IP allowlist. The page reports whatever the
 *    allowlist says; it does not try to work around it.
 *
 * Everything the external server returns is escaped by the template before it reaches the page, and a
 * binary body is never written into the HTML at all — only its type and size are.
 */
final readonly class Action
{
    // ---- Test defaults. These six constants are the only place the pre-filled values are set. ---------
    // API 1 — Latest Calls
    private const DEFAULT_ACCOUNT_ID = 871;
    private const DEFAULT_LIMIT = 100;
    // API 2 — Fetch Recording
    private const DEFAULT_CALL_SESSION_ID = '18794639';
    private const DEFAULT_TIME = '2026-03-11';
    private const DEFAULT_COMPANY = 'SWCC';
    private const DEFAULT_NAME = 'test';
    // --------------------------------------------------------------------------------------------------

    /** Guard against a typo'd `?limit=` asking the provider for an unreasonable page. */
    private const MAX_LIMIT = 500;

    /** A call session id is a numeric identifier; this bounds a typo, not the provider's id space. */
    private const MAX_ID_DIGITS = 20;

    /** Company and name are free text on the provider's side, so cap them at something sane. */
    private const MAX_TEXT_LENGTH = 100;

    private const API_LATEST_CALLS = 'latest-calls';
    private const API_FETCH_RECORDING = 'fetch-recording';

    public function __construct(
        private WebViewRenderer $viewRenderer,
        private RecordingApiProbe $probe,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $api = $this->text($params['api'] ?? null);

        // Form values are echoed back exactly as typed, so a rejected value stays visible for correction.
        $accountIdRaw = $this->text($params['account_id'] ?? null, (string) self::DEFAULT_ACCOUNT_ID);
        $limitRaw = $this->text($params['limit'] ?? null, (string) self::DEFAULT_LIMIT);
        $callSessionIdRaw = $this->text($params['call_session_id'] ?? null, self::DEFAULT_CALL_SESSION_ID);
        $timeRaw = $this->text($params['time'] ?? null, self::DEFAULT_TIME);
        $companyRaw = $this->text($params['company'] ?? null, self::DEFAULT_COMPANY);
        $nameRaw = $this->text($params['name'] ?? null, self::DEFAULT_NAME);

        $latest = $api === self::API_LATEST_CALLS
            ? $this->runLatestCalls($accountIdRaw, $limitRaw)
            : null;

        $fetch = $api === self::API_FETCH_RECORDING
            ? $this->runFetchRecording($callSessionIdRaw, $timeRaw, $companyRaw, $nameRaw)
            : null;

        return $this->viewRenderer
            ->withLayout('@src/Web/Shared/Layout/Admin/layout.php')
            ->render(__DIR__ . '/template', [
                'apiLatestCalls' => self::API_LATEST_CALLS,
                'apiFetchRecording' => self::API_FETCH_RECORDING,
                'maxLimit' => self::MAX_LIMIT,
                'accountIdRaw' => $accountIdRaw,
                'limitRaw' => $limitRaw,
                'callSessionIdRaw' => $callSessionIdRaw,
                'timeRaw' => $timeRaw,
                'companyRaw' => $companyRaw,
                'nameRaw' => $nameRaw,
                'latest' => $latest,
                'fetch' => $fetch,
            ]);
    }

    /**
     * API 1: validate, call, and decode enough of the result for the convenience table.
     *
     * @return array{validationError: ?string, result: ?ProbeResult, pretty: ?string, rows: list<array{callTime: string, callSessionId: string, orderId: string}>, failure: ?string}
     */
    private function runLatestCalls(string $accountIdRaw, string $limitRaw): array
    {
        $accountId = $this->positiveInt($accountIdRaw);
        $limit = $this->positiveInt($limitRaw);

        $validationError = match (true) {
            $accountId === null => 'Account ID must be a positive integer.',
            $limit === null => 'Limit must be a positive integer.',
            $limit > self::MAX_LIMIT => sprintf('Limit must be between 1 and %d.', self::MAX_LIMIT),
            default => null,
        };

        if ($validationError !== null || $accountId === null || $limit === null) {
            return $this->outcome(validationError: $validationError ?? 'Invalid request.');
        }

        try {
            $result = $this->probe->fetchLatestCalls($accountId, $limit);
        } catch (Throwable $e) {
            return $this->outcome(failure: $this->describe($e));
        }

        $pretty = $result->isReadableText() ? $this->prettyJson($result->preview) : null;

        return $this->outcome(
            result: $result,
            pretty: $pretty,
            rows: $result->isReadableText() ? $this->callRows($result->preview) : [],
        );
    }

    /**
     * API 2: validate every field before anything is put on the wire, then call.
     *
     * @return array{validationError: ?string, result: ?ProbeResult, pretty: ?string, rows: list<array{callTime: string, callSessionId: string, orderId: string}>, failure: ?string}
     */
    private function runFetchRecording(string $callSessionId, string $time, string $company, string $name): array
    {
        $validationError = match (true) {
            preg_match('/^\d{1,' . self::MAX_ID_DIGITS . '}$/', $callSessionId) !== 1
                => 'Call Session ID is required and must be digits only.',
            !$this->isCalendarDate($time)
                => 'Time is required and must be a real date in YYYY-MM-DD format.',
            !$this->isSafeText($company)
                => sprintf('Company is required and must be at most %d characters.', self::MAX_TEXT_LENGTH),
            !$this->isSafeText($name)
                => sprintf('Name is required and must be at most %d characters.', self::MAX_TEXT_LENGTH),
            default => null,
        };

        if ($validationError !== null) {
            return $this->outcome(validationError: $validationError);
        }

        try {
            $result = $this->probe->fetchRecording($callSessionId, $time, $company, $name);
        } catch (Throwable $e) {
            return $this->outcome(failure: $this->describe($e));
        }

        return $this->outcome(
            result: $result,
            pretty: $result->isReadableText() ? $this->prettyJson($result->preview) : null,
        );
    }

    /**
     * @param list<array{callTime: string, callSessionId: string, orderId: string}> $rows
     *
     * @return array{validationError: ?string, result: ?ProbeResult, pretty: ?string, rows: list<array{callTime: string, callSessionId: string, orderId: string}>, failure: ?string}
     */
    private function outcome(
        ?string $validationError = null,
        ?ProbeResult $result = null,
        ?string $pretty = null,
        array $rows = [],
        ?string $failure = null,
    ): array {
        return [
            'validationError' => $validationError,
            'result' => $result,
            'pretty' => $pretty,
            'rows' => $rows,
            'failure' => $failure,
        ];
    }

    /**
     * The convenience table under API 1's raw output. Best-effort by design: a body that is not a list of
     * call objects simply yields no rows, and the raw response above it still tells the whole story.
     *
     * @return list<array{callTime: string, callSessionId: string, orderId: string}>
     */
    private function callRows(string $raw): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $rows = [];
        /** @var mixed $item */
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $rows[] = [
                'callTime' => $this->scalar($item['callTime'] ?? null),
                'callSessionId' => $this->scalar($item['callSessionId'] ?? null),
                'orderId' => $this->scalar($item['orderId'] ?? null),
            ];
        }

        return $rows;
    }

    /** Readability only — the raw body is always shown alongside it. */
    private function prettyJson(string $raw): ?string
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return json_encode(
                $decoded,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Deliberately verbose: diagnosing DNS/TLS/timeout is the whole point of this page, and it is behind
     * admin authentication.
     */
    private function describe(Throwable $e): string
    {
        return sprintf(
            "%s: %s\n\nthrown in %s:%d\n\n%s",
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        );
    }

    private function positiveInt(string $raw): ?int
    {
        if (preg_match('/^\d{1,9}$/', $raw) !== 1) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    /** A real calendar date, not merely something shaped like one: `2026-02-31` is rejected. */
    private function isCalendarDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    /** Non-empty, bounded, and free of control characters that have no business in a query string. */
    private function isSafeText(string $value): bool
    {
        return $value !== ''
            && mb_strlen($value) <= self::MAX_TEXT_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    private function text(mixed $raw, string $default = ''): string
    {
        if (!is_scalar($raw)) {
            return $default;
        }

        $value = trim((string) $raw);

        return $value === '' ? $default : $value;
    }

    private function scalar(mixed $raw): string
    {
        return is_scalar($raw) ? (string) $raw : '';
    }
}
