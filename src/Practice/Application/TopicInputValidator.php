<?php

declare(strict_types=1);

namespace App\Practice\Application;

use App\Shared\Domain\Exception\ValidationException;

use function mb_strlen;
use function preg_replace;
use function trim;

/**
 * What a topic name has to be before anything stores it.
 *
 * Shape only. Whether the name is already taken is the database's answer, not this class's — asking
 * first and inserting afterwards would let two requests both pass the check before either wrote.
 *
 * The error keys are the form's input names, so the template can put each message under its own field.
 */
final class TopicInputValidator
{
    public const NAME_MAX = 120;
    public const DESCRIPTION_MAX = 500;

    /**
     * @return array{0: string, 1: string|null} the cleaned name and description
     *
     * @throws ValidationException
     */
    public function validate(string $name, ?string $description): array
    {
        $errors = [];

        // Collapse runs of whitespace as well as trimming: "Sushi   Roll" and "Sushi Roll" are the
        // same topic to a person, and storing both would make the duplicate rule look broken.
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $description = $description === null ? null : trim($description);

        if ($name === '') {
            $errors['name'] = 'Enter a topic name.';
        } elseif (mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = 'Keep the topic name to ' . self::NAME_MAX . ' characters or fewer.';
        }

        if ($description !== null && mb_strlen($description) > self::DESCRIPTION_MAX) {
            $errors['description'] = 'Keep the description to ' . self::DESCRIPTION_MAX . ' characters or fewer.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$name, $description === '' ? null : $description];
    }

    /**
     * The form the uniqueness rule compares on: trimmed, collapsed, lowercased.
     *
     * Lives here rather than in the repository only because the repository needs it too and one
     * definition is the point — "Change Dish", "change dish" and "  CHANGE   DISH " are one topic.
     */
    public static function fold(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
