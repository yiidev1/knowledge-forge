<?php

declare(strict_types=1);

namespace App\Order58\Client;

use App\Order58\Contract\Dto\Order58ErrorDetails;
use App\Order58\Contract\Exception\Order58AuthenticationFailed;
use App\Order58\Contract\Exception\Order58Exception;
use App\Order58\Contract\Exception\Order58InvalidResponse;
use App\Order58\Contract\Exception\Order58RateLimited;
use App\Order58\Contract\Exception\Order58RequestFailed;
use App\Order58\Contract\Exception\Order58Timeout;
use App\Order58\Contract\Exception\Order58TransportFailed;
use App\Shared\Infrastructure\Log\SecretRedactor;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;

/**
 * Translates HTTP failures into typed, redacted {@see Order58Exception}s with correct retry semantics, and
 * never lets a raw body or a URL containing the token escape into a message.
 */
final readonly class Order58ErrorMapper
{
    public function __construct(
        private SecretRedactor $redactor,
    ) {}

    public function fromResponse(ResponseInterface $response, string $bodyText): Order58Exception
    {
        $status = $response->getStatusCode();
        $providerMessage = $this->extractProviderMessage($bodyText);
        $providerCode = $this->extractProviderCode($bodyText);

        return match (true) {
            $status === 401, $status === 403 => new Order58AuthenticationFailed(Order58ErrorDetails::of(
                code: 'auth_failed',
                safeMessage: 'The Order58 Integration API rejected the credentials. Check ORDER58_API_TOKEN.',
                httpStatus: $status,
                // `/authenticate` answers a wrong *user* password with 401 too, so the caller needs the
                // provider's own code to tell that apart from our service token being refused.
                providerCode: $providerCode,
            )),
            $status === 429 => new Order58RateLimited(Order58ErrorDetails::of(
                code: 'rate_limited',
                safeMessage: 'Order58 rate limit reached. ' . ($providerMessage ?? 'Retrying shortly.'),
                httpStatus: $status,
                transient: true,
                retryAfterSeconds: $this->retryAfter($response),
            )),
            $status >= 500 => new Order58TransportFailed(Order58ErrorDetails::of(
                code: 'server_error',
                safeMessage: 'Order58 returned a server error (' . $status . ').',
                httpStatus: $status,
                transient: true,
            )),
            default => new Order58RequestFailed(Order58ErrorDetails::of(
                code: 'request_rejected',
                safeMessage: 'Order58 rejected the request (' . $status . '). ' . ($providerMessage ?? ''),
                httpStatus: $status,
            )),
        };
    }

    public function fromTransportException(Throwable $e): Order58Exception
    {
        if ($e instanceof NetworkExceptionInterface) {
            return new Order58Timeout(Order58ErrorDetails::of(
                code: 'network_error',
                safeMessage: 'Could not reach Order58 (network error or timeout).',
                transient: true,
            ), $e);
        }

        return new Order58TransportFailed(Order58ErrorDetails::of(
            code: 'transport_error',
            safeMessage: 'The request to Order58 failed in transport.',
            transient: true,
        ), $e);
    }

    public function invalidResponse(string $reason, ?ResponseInterface $response = null): Order58InvalidResponse
    {
        return new Order58InvalidResponse(Order58ErrorDetails::of(
            code: 'invalid_response',
            safeMessage: 'Order58 returned a response that could not be understood: ' . $this->redactor->redact($reason),
            httpStatus: $response?->getStatusCode(),
        ));
    }

    private function retryAfter(ResponseInterface $response): ?int
    {
        $value = $response->getHeaderLine('retry-after');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The provider's `error.code`, e.g. `UNAUTHORIZED` or `INVALID_CREDENTIALS`. A short upstream enum, not
     * free text — it is never shown to a user, only used to classify.
     */
    private function extractProviderCode(string $bodyText): ?string
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
        if (!is_array($error) || !isset($error['code'])) {
            return null;
        }

        $code = $error['code'];

        return is_string($code) ? $code : null;
    }

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
