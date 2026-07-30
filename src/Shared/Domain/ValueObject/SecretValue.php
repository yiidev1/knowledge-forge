<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use LogicException;
use SensitiveParameter;

use function strlen;

/**
 * A credential that resists accidental disclosure.
 *
 * The API key and the database password pass through logs, exception traces, `var_dump()` output and
 * debug error pages far more easily than anyone expects. This wrapper closes the common leaks:
 *
 * - `__toString()` throws, so string interpolation into a log message or a template is a hard error
 *   rather than a silent disclosure.
 * - `__debugInfo()` redacts, so `var_dump()`, `print_r()` and the Yii error-handler's variable dump
 *   show a placeholder.
 * - `reveal()` is the single, greppable point where the plaintext is obtained.
 */
final class SecretValue
{
    /**
     * The secret XOR-ed with {@see $pad}, never the plaintext.
     *
     * `__debugInfo()` covers `var_dump()` and `print_r()`, but it does NOT cover `var_export()`, which
     * walks properties by reflection and prints them verbatim — as do most reflection-based dumpers,
     * including the ones an error handler reaches for while rendering a debug page. Masking the bytes
     * at rest is the only defence that holds regardless of how the object is inspected.
     */
    private string $masked;

    private string $pad;

    /**
     * Digest is computed once, at construction, so that `digest()` never has to unmask.
     */
    private string $digest;

    private int $length;

    public function __construct(
        #[SensitiveParameter]
        string $value,
    ) {
        $this->length = strlen($value);

        if ($value === '') {
            $this->digest = '<unset>';
            $this->pad = '';
            $this->masked = '';

            return;
        }

        $this->digest = 'sha256:' . substr(hash('sha256', $value), 0, 8);
        $this->pad = random_bytes(strlen($value));
        $this->masked = $value ^ $this->pad;
    }

    /**
     * The plaintext. Call this as late as possible and never assign the result to a property.
     */
    public function reveal(): string
    {
        return $this->masked ^ $this->pad;
    }

    public function isEmpty(): bool
    {
        return $this->length === 0;
    }

    /**
     * Stable, non-reversible identifier for the secret, safe to log and to compare across processes.
     */
    public function digest(): string
    {
        return $this->digest;
    }

    /**
     * Length is safe to expose and is genuinely useful when diagnosing a truncated or padded key.
     */
    public function length(): int
    {
        return $this->length;
    }

    /**
     * Serialising a secret is never intentional; refusing is safer than writing it to a session, a
     * cache entry or a queue payload.
     *
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new LogicException('A SecretValue must not be serialized.');
    }

    /**
     * Covers `var_dump()` and `print_r()`. `var_export()` ignores this hook entirely, which is why the
     * value is masked at rest rather than relying on it.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '***redacted***', 'digest' => $this->digest];
    }

    public function __toString(): string
    {
        throw new LogicException(
            'A SecretValue must not be converted to a string. Call reveal() at the point of use.',
        );
    }
}
