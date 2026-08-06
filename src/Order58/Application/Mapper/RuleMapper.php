<?php

declare(strict_types=1);

namespace App\Order58\Application\Mapper;

use App\Order58\Contract\Dto\Order58RuleRecord;
use App\Order58\Domain\RuleMirror;

/**
 * Maps an Order58 rule record into a {@see RuleMirror}. The snapshot is the source record minus `_sync_hash`
 * (kept for audit; it carries no credentials). A freshly mapped record is active.
 */
final class RuleMapper
{
    public function toMirror(Order58RuleRecord $record): RuleMirror
    {
        $snapshot = $record->raw;
        unset($snapshot['_sync_hash']);

        return new RuleMirror(
            id: null,
            sourceId: $record->id,
            type: $record->type,
            title: $record->title,
            description: $record->description,
            ruleKeyword: $record->ruleKeyword,
            createdName: $record->createdName,
            sourceStoreId: $record->sourceStoreId,
            active: true,
            syncHash: $record->syncHash,
            sourceCreatedAt: $record->createdAt,
            sourceUpdatedAt: $record->updatedAt,
            snapshot: $snapshot,
        );
    }
}
