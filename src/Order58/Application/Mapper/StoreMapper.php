<?php

declare(strict_types=1);

namespace App\Order58\Application\Mapper;

use App\Order58\Application\Formatter\StoreProfileFields;
use App\Order58\Contract\Dto\Order58Account;
use App\Order58\Domain\StoreMirror;

use function is_scalar;

/**
 * Maps an Order58 account into a {@see StoreMirror}, building a curated, credential-free snapshot that is
 * the single deterministic source for the store-profile document. Accounts have no reliable source-updated
 * timestamp, so `sourceUpdatedAt` is always null.
 */
final class StoreMapper
{
    public function toMirror(Order58Account $account): StoreMirror
    {
        $fields = [];
        foreach (StoreProfileFields::FIELDS as $key => $label) {
            $value = $account->raw[$key] ?? null;
            if (is_scalar($value) && $value !== '' && $value !== 0) {
                $fields[$label] = $value;
            }
        }

        $snapshot = [
            'id' => $account->id,
            'name' => $account->name,
            'active' => $account->active,
            'fields' => $fields,
        ];

        return new StoreMirror(
            id: null,
            sourceId: $account->id,
            name: $account->name,
            company: $account->company,
            active: $account->active,
            syncHash: $account->syncHash,
            sourceUpdatedAt: null,
            snapshot: $snapshot,
        );
    }
}
