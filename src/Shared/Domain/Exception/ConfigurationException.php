<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use function sprintf;

/**
 * A required setting is missing or unusable.
 *
 * Raised when a parameter object is built, so a subsystem fails at the moment it is first needed, with
 * a message naming the variable to fix. The offending value is never included: these frequently name
 * credentials.
 */
final class ConfigurationException extends DomainException
{
    public function errorCode(): string
    {
        return 'configuration_invalid';
    }

    public static function missing(string $variable, string $purpose): self
    {
        return new self(
            sprintf(
                'Environment variable %s is not set. It is required to %s. See .env.example and run "./yii kf:health".',
                $variable,
                $purpose,
            ),
        );
    }

    public static function invalid(string $variable, string $expectation): self
    {
        return new self(
            sprintf('Environment variable %s is invalid. Expected %s. See .env.example.', $variable, $expectation),
        );
    }
}
