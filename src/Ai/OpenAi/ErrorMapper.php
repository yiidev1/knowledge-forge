<?php

declare(strict_types=1);

namespace App\Ai\OpenAi;

use App\Ai\Contract\Dto\AiErrorDetails;
use App\Ai\Contract\Exception\AiAuthenticationFailed;
use App\Ai\Contract\Exception\AiException;
use App\Ai\Contract\Exception\AiInvalidResponse;
use App\Ai\Contract\Exception\AiProcessingFailed;
use App\Ai\Contract\Exception\AiRateLimited;
use App\Ai\Contract\Exception\AiRequestTooLarge;
use App\Ai\Contract\Exception\AiTimeout;
use App\Ai\Contract\Exception\AiTransportFailed;
use App\Shared\Infrastructure\Log\SecretRedactor;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;

/**
 * Translates HTTP failures into typed, provider-neutral {@see AiException}s with correct retry and
 * side-effect semantics — and never lets a raw provider body or a URL containing a key escape.
 *
 * Side-effect classification is deliberately conservative for transport failures: a network error
 * (including a cURL timeout, which cannot distinguish connect from read) is treated as possibly having
 * reached the server. Combined with the per-request idempotency flag in the retry policy, this ensures
 * a non-idempotent create is reconciled rather than blindly resent.
 */
final readonly class ErrorMapper
{
    public function __construct(
        private SecretRedactor $redactor,
    ) {}

    public function fromResponse(ResponseInterface $response, string $bodyText): AiException
    {
        $status = $response->getStatusCode();
        $requestId = $this->requestId($response);
        $providerMessage = $this->extractProviderMessage($bodyText);

        return match (true) {
            $status === 401, $status === 403 => new AiAuthenticationFailed(AiErrorDetails::of(
                code: 'auth_failed',
                safeMessage: 'The OpenAI API rejected the credentials. Check OPENAI_API_KEY.',
                httpStatus: $status,
                requestId: $requestId,
            )),
            $status === 429 => new AiRateLimited(AiErrorDetails::of(
                code: 'rate_limited',
                safeMessage: 'OpenAI rate limit reached. ' . ($providerMessage ?? 'Retrying shortly.'),
                httpStatus: $status,
                requestId: $requestId,
                transient: true,
                retryAfterSeconds: $this->retryAfter($response),
            )),
            $status === 413 => new AiRequestTooLarge(AiErrorDetails::of(
                code: 'request_too_large',
                safeMessage: 'The request was too large for OpenAI to accept.',
                httpStatus: $status,
                requestId: $requestId,
            )),
            $status >= 500 => new AiTransportFailed(AiErrorDetails::of(
                code: 'server_error',
                safeMessage: 'OpenAI returned a server error (' . $status . ').',
                httpStatus: $status,
                requestId: $requestId,
                transient: true,
                // The server received the request; the outcome of a non-idempotent call is unknown.
                sideEffectPossible: true,
            )),
            default => new AiProcessingFailed(AiErrorDetails::of(
                code: 'request_rejected',
                safeMessage: 'OpenAI rejected the request (' . $status . '). ' . ($providerMessage ?? ''),
                httpStatus: $status,
                requestId: $requestId,
            )),
        };
    }

    public function fromTransportException(Throwable $e): AiException
    {
        // A PSR-18 network exception means the request could not be completed. It cannot be
        // distinguished from a read timeout after the bytes were sent, so treat it as possibly
        // effective — the retry policy will still allow a retry for idempotent requests.
        if ($e instanceof NetworkExceptionInterface) {
            return new AiTimeout(AiErrorDetails::of(
                code: 'network_error',
                safeMessage: 'Could not reach OpenAI (network error or timeout).',
                transient: true,
                sideEffectPossible: true,
            ), $e);
        }

        return new AiTransportFailed(AiErrorDetails::of(
            code: 'transport_error',
            safeMessage: 'The request to OpenAI failed in transport.',
            transient: true,
            sideEffectPossible: true,
        ), $e);
    }

    public function invalidResponse(string $reason, ?ResponseInterface $response = null): AiInvalidResponse
    {
        return new AiInvalidResponse(AiErrorDetails::of(
            code: 'invalid_response',
            safeMessage: 'OpenAI returned a response that could not be understood: ' . $this->redactor->redact($reason),
            httpStatus: $response?->getStatusCode(),
            requestId: $response !== null ? $this->requestId($response) : null,
        ));
    }

    private function requestId(ResponseInterface $response): ?string
    {
        $value = $response->getHeaderLine('x-request-id');

        return $value === '' ? null : $value;
    }

    private function retryAfter(ResponseInterface $response): ?int
    {
        $value = $response->getHeaderLine('retry-after');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Pulls the provider's own `error.message`, redacted, so a useful reason survives without risking a
     * credential leak from an echoed request.
     */
    private function extractProviderMessage(string $bodyText): ?string
    {
        if ($bodyText === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($bodyText, true);
        if (!is_array($decoded) || !isset($decoded['error'])) {
            return null;
        }

        $error = $decoded['error'];
        if (!is_array($error) || !isset($error['message'])) {
            return null;
        }

        $message = $error['message'];

        return is_string($message) ? $this->redactor->redact($message) : null;
    }
}
