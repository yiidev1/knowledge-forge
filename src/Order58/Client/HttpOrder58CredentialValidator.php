<?php

declare(strict_types=1);

namespace App\Order58\Client;

use App\Order58\Contract\Dto\Order58ValidationOutcome;
use App\Order58\Contract\Dto\Order58ValidationResult;
use App\Order58\Contract\Order58CredentialValidatorInterface;
use App\Shared\Infrastructure\Log\SafeLogContext;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use JsonException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Calls the Order58 fallback validate API over PSR-18.
 *
 * `POST <configured url>` with `Content-Type: application/json`, `Authorization: Bearer <static token>` and
 * a body of exactly `{"login": …, "password": …}`. TLS verification is left at the transport's default (on);
 * nothing here disables it. There are no retries: the caller is a human waiting on a login form, this runs
 * only after a primary call has already failed, and a repeated password submission is not something to
 * multiply on our side.
 *
 * Classification, and why it matters: only an integer `status` of 200 (valid) or a status in
 * {@see self::CREDENTIAL_REJECT_STATUSES} (rejected) is a verdict about the *user's* password. Everything
 * else describes the integration and comes back as `AuthFailed` / `UpstreamError` / `NetworkError` /
 * `InvalidResponse`, so the caller can avoid charging the login throttle for our own outage.
 *
 * Logging is endpoint, HTTP status and reason code only. The password, the Bearer token, the login and the
 * raw body are never written, and `SafeLogContext` drops anything not on its allowlist regardless.
 */
final readonly class HttpOrder58CredentialValidator implements Order58CredentialValidatorInterface
{
    /**
     * Body `status` values that mean "these credentials are not valid".
     *
     * `/api/user/validate` does exactly one thing — check a login and a password — so a well-formed 400 from
     * it is a rejection of the submitted credentials, and is reported to the user with the application's own
     * generic message rather than the provider's wording.
     *
     * 401 and 403 are deliberately absent: on this endpoint they mean *our* static Bearer token was refused,
     * which is a configuration fault and must not read as the agent's password being wrong.
     *
     * If Order58 ever confirms that 400 also carries non-credential errors, removing it here is the single
     * change required — such responses then fall through to `UpstreamError` and their `message` is shown.
     */
    private const CREDENTIAL_REJECT_STATUSES = [400];

    /** Upstream text is shown to an unauthenticated visitor, so it is capped hard. */
    private const MAX_MESSAGE_LENGTH = 200;

    private const ENDPOINT = 'order58-validate:user/validate';

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private Order58ValidateCredentials $credentials,
        private LoggerInterface $logger,
        private SafeLogContext $logContext,
    ) {}

    public function validate(
        string $login,
        #[SensitiveParameter]
        string $password,
    ): Order58ValidationResult {
        if (!$this->credentials->isComplete()) {
            // Switched off, not broken — but still not a statement about the password.
            return $this->result(Order58ValidationOutcome::NotConfigured, null, null);
        }

        try {
            $request = $this->requestFactory
                ->createRequest('POST', $this->credentials->url)
                ->withHeader('Authorization', 'Bearer ' . $this->credentials->token->reveal())
                ->withHeader('Accept', 'application/json')
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(
                    json_encode(['login' => $login, 'password' => $password], JSON_THROW_ON_ERROR),
                ));

            $response = $this->httpClient->sendRequest($request);
        } catch (NetworkExceptionInterface) {
            // Timeout, DNS, TLS and connection refusal all arrive here.
            return $this->result(Order58ValidationOutcome::NetworkError, null, null);
        } catch (ClientExceptionInterface|JsonException) {
            // Any other transport failure, or a body we could not even encode. Deliberately not `Throwable`:
            // a programming error should surface, not be laundered into "temporarily unavailable".
            return $this->result(Order58ValidationOutcome::UpstreamError, null, null);
        }

        $httpStatus = $response->getStatusCode();

        // Our own credential being refused is never the agent's problem, whatever the body says.
        if ($httpStatus === 401 || $httpStatus === 403) {
            return $this->result(Order58ValidationOutcome::AuthFailed, null, $httpStatus);
        }

        $body = $this->decode((string) $response->getBody());
        if ($body === null) {
            return $this->result(Order58ValidationOutcome::InvalidResponse, null, $httpStatus);
        }

        $status = $body['status'] ?? null;
        if (!is_int($status)) {
            // Covers a missing status and the string "200", which must never pass as success.
            return $this->result(Order58ValidationOutcome::InvalidResponse, null, $httpStatus);
        }

        if ($status === 200) {
            // `message` ("SUCCESS") and `account_id` are both deliberately ignored.
            return $this->result(Order58ValidationOutcome::Valid, null, $httpStatus);
        }

        if (in_array($status, self::CREDENTIAL_REJECT_STATUSES, true)) {
            return $this->result(Order58ValidationOutcome::CredentialsRejected, null, $httpStatus);
        }

        if ($httpStatus >= 500 || $status >= 500) {
            return $this->result(Order58ValidationOutcome::UpstreamError, null, $httpStatus);
        }

        // A business error we cannot interpret but which carries something worth telling the user.
        return $this->result(
            Order58ValidationOutcome::UpstreamError,
            $this->safeMessage($body['message'] ?? null),
            $httpStatus,
        );
    }

    /**
     * Reduces untrusted upstream text to something that can be shown on a login page: a single-line,
     * trimmed, length-capped string, or null if there is nothing usable. Escaping happens at render time;
     * this governs shape and size only.
     */
    private function safeMessage(mixed $message): ?string
    {
        if (!is_string($message)) {
            return null;
        }

        // Collapse newlines, tabs and C0 control characters, then runs of spaces, so provider text cannot
        // break the flash layout or pad itself out with whitespace.
        $flat = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? '';
        $flat = trim(preg_replace('/\s{2,}/u', ' ', $flat) ?? '');

        if ($flat === '') {
            return null;
        }

        return mb_strlen($flat) > self::MAX_MESSAGE_LENGTH
            ? mb_substr($flat, 0, self::MAX_MESSAGE_LENGTH) . '…'
            : $flat;
    }

    /**
     * @return array<array-key, mixed>|null Null when the body is not a usable JSON object.
     */
    private function decode(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function result(
        Order58ValidationOutcome $outcome,
        ?string $safeMessage,
        ?int $httpStatus,
    ): Order58ValidationResult {
        $context = ['endpoint' => self::ENDPOINT, 'reason' => $outcome->reason()];
        if ($httpStatus !== null) {
            $context['status'] = $httpStatus;
        }

        $this->logger->info('order58 validate', $this->logContext->build($context));

        return Order58ValidationResult::of($outcome, $safeMessage);
    }
}
