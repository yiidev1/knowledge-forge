<?php

declare(strict_types=1);

namespace App\Order58\Application\Mapper;

use App\Order58\Contract\ActiveFlag;
use App\Order58\Contract\Dto\Order58RuleRecord;
use App\Order58\Domain\RuleMirror;

/**
 * Maps an Order58 rule record into a {@see RuleMirror}. The snapshot is the source record minus `_sync_hash`
 * (kept for audit; it carries no credentials).
 *
 * Today's Rules API omits an explicit active/status field, so presence in a successful list response means
 * active. If upstream later sends `active` (bool/0/1), that value wins.
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
            active: self::resolveActive($record),
            syncHash: $record->syncHash,
            sourceCreatedAt: $record->createdAt,
            sourceUpdatedAt: $record->updatedAt,
            snapshot: $snapshot,
        );
    }

    /**
     * Authoritative active state for sync: explicit upstream flag when present, otherwise presence = active.
     */
    public static function resolveActive(Order58RuleRecord $record): bool
    {
        return ActiveFlag::normalize($record->raw['active'] ?? null) ?? true;
    }
}
