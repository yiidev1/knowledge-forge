<?php

declare(strict_types=1);

namespace App\Order58\Application\Mapper;

use App\Order58\Contract\Dto\Order58KnowledgeRecord;
use App\Order58\Domain\KnowledgeMirror;

/**
 * Maps an Order58 knowledge record into a {@see KnowledgeMirror}. The snapshot is the source record minus
 * `_sync_hash` (kept for audit; it carries no credentials). A freshly mapped record is active.
 */
final class KnowledgeMapper
{
    public function toMirror(Order58KnowledgeRecord $record): KnowledgeMirror
    {
        $snapshot = $record->raw;
        unset($snapshot['_sync_hash']);

        return new KnowledgeMirror(
            id: null,
            sourceId: $record->id,
            storeSourceId: $record->storeId,
            title: $record->title,
            content: $record->content,
            knowledgeNumber: $record->knowledgeNumber,
            keyword: $record->keyword,
            type: $record->type,
            active: true,
            syncHash: $record->syncHash,
            sourceCreatedAt: $record->createdAt,
            sourceUpdatedAt: $record->updatedAt,
            snapshot: $snapshot,
        );
    }
}
