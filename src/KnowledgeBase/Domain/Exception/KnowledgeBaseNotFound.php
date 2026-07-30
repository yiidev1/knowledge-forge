<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundException;

use function sprintf;

/**
 * A knowledge base does not exist. Raised for both a missing id and a missing slug, so a guessed
 * identifier yields a plain 404 with nothing to distinguish "never existed" from "exists but hidden".
 */
final class KnowledgeBaseNotFound
{
    public static function withSlug(string $slug): NotFoundException
    {
        return new NotFoundException(
            'knowledge_base_not_found',
            sprintf('Knowledge base "%s" was not found.', $slug),
        );
    }

    public static function withId(int $id): NotFoundException
    {
        return new NotFoundException(
            'knowledge_base_not_found',
            sprintf('Knowledge base #%d was not found.', $id),
        );
    }
}
