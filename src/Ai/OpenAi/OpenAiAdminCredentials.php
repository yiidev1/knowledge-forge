<?php

declare(strict_types=1);

namespace App\Ai\OpenAi;

use App\Shared\Domain\ValueObject\SecretValue;
use SensitiveParameter;

use function rtrim;

/**
 * The OPTIONAL organization-admin credential, held separately from {@see OpenAiCredentials}.
 *
 * Separate rather than an extra field on the existing object for two reasons: the project key must never
 * be silently promoted to an admin key, and `OpenAiCredentials::isComplete()`/`assertComplete()` express
 * "the application cannot work without these" — which is exactly what this credential is NOT.
 *
 * `$apiKey` is `null` when the variable is unset or empty; an empty `SecretValue` is never constructed.
 * That makes "configured" a single unambiguous check rather than a value that exists but is blank —
 * the ambiguity that otherwise ends with a `Bearer ` header being sent with nothing after it.
 */
final readonly class OpenAiAdminCredentials
{
    public ?SecretValue $apiKey;
    public string $baseUrl;

    public function __construct(
        #[SensitiveParameter]
        string $apiKey,
        string $baseUrl,
    ) {
        $this->apiKey = $apiKey === '' ? null : new SecretValue($apiKey);
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null;
    }
}
