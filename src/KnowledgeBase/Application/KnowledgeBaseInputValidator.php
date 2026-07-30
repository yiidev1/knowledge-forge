<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Application;

use App\Shared\Domain\Exception\ValidationException;

use function mb_strlen;
use function sprintf;
use function trim;

/**
 * Shared field validation for creating and updating a knowledge base.
 *
 * Kept in one place so the create and update paths cannot drift apart on limits. Throws a
 * {@see ValidationException} whose field keys line up with the form inputs, so the web layer can place
 * each message against the right control.
 */
final class KnowledgeBaseInputValidator
{
    public const NAME_MAX = 160;
    public const DESCRIPTION_MAX = 2000;
    public const INSTRUCTIONS_MAX = 10000;

    /**
     * @throws ValidationException
     */
    public static function validate(string $name, ?string $description, ?string $systemInstructions): void
    {
        $errors = [];

        $name = trim($name);
        if ($name === '') {
            $errors['name'] = 'Enter a name.';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = sprintf('Name must be at most %d characters.', self::NAME_MAX);
        }

        if ($description !== null && mb_strlen(trim($description)) > self::DESCRIPTION_MAX) {
            $errors['description'] = sprintf('Description must be at most %d characters.', self::DESCRIPTION_MAX);
        }

        if ($systemInstructions !== null && mb_strlen(trim($systemInstructions)) > self::INSTRUCTIONS_MAX) {
            $errors['system_instructions'] = sprintf('Instructions must be at most %d characters.', self::INSTRUCTIONS_MAX);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }
}
