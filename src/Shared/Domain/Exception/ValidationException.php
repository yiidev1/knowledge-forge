<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * One or more inputs failed validation.
 *
 * Carries a field-keyed map of messages so a web action can re-render a form with the errors placed
 * against the right inputs. Application services throw this for invariants they alone can check
 * (a duplicate slug, a duplicate rule name); simple shape checks (required, length) are done in the
 * web layer before the service is called.
 */
final class ValidationException extends DomainException
{
    /**
     * @param array<string, string> $errors Field name => human-readable message.
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'Validation failed.',
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'validation_failed';
    }

    /**
     * @param non-empty-string $field
     */
    public static function forField(string $field, string $message): self
    {
        return new self([$field => $message], $message);
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
