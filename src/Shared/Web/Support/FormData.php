<?php

declare(strict_types=1);

namespace App\Shared\Web\Support;

use Psr\Http\Message\ServerRequestInterface;

use function is_array;
use function is_string;
use function trim;

/**
 * Typed, defensive access to a POST body.
 *
 * A PSR-7 parsed body is `mixed`; this narrows each field to a string (or null) so actions never index
 * into an untyped array. Values are read exactly as submitted — trimming and emptiness are the caller's
 * decision — except {@see string()} which trims, since almost every text field wants that.
 */
final readonly class FormData
{
    /**
     * @param array<array-key, mixed> $data
     */
    private function __construct(
        private array $data,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();

        return new self(is_array($body) ? $body : []);
    }

    /**
     * Trimmed string value, or '' when absent or not a string.
     */
    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Trimmed value, or null when absent, non-string, or empty after trimming. For optional fields.
     */
    public function nullableString(string $key): ?string
    {
        $value = $this->string($key);

        return $value === '' ? null : $value;
    }

    /**
     * Raw (untrimmed) string, for fields where surrounding whitespace could be meaningful.
     */
    public function raw(string $key): string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * The raw value at a key, for callers that must handle an array (e.g. an ordered id list).
     */
    public function rawValue(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
