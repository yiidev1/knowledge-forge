<?php

declare(strict_types=1);

namespace App\Document\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundException;

use function sprintf;

/**
 * A document does not exist within the given knowledge base. Scoped lookups (document id + KB id) raise
 * this when the pair does not match, so a document id cannot be used against another knowledge base.
 */
final class DocumentNotFound
{
    public static function inKnowledgeBase(int $documentId, int $knowledgeBaseId): NotFoundException
    {
        return new NotFoundException(
            'document_not_found',
            sprintf('Document #%d was not found in knowledge base #%d.', $documentId, $knowledgeBaseId),
        );
    }
}
