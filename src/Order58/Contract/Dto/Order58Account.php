<?php

declare(strict_types=1);

namespace App\Order58\Contract\Dto;

/**
 * A store, from Order58 `account` records. Only the fields Knowledge Forge uses are typed; the full
 * validated record is kept in {@see $raw} so the deterministic store-profile formatter and the safe
 * snapshot can select from it without the client having to know every column.
 *
 * The stable source store id is `account.id`. `account_id` on the record is employer/parent data and is
 * deliberately NOT exposed here — it is never a store identifier and never authorization.
 */
final readonly class Order58Account
{
    /**
     * @param bool $active      The normalized source-active flag; only meaningful when {@see $activeKnown}
     *                          is true. When the API value was missing or unrecognised this is `false` as a
     *                          placeholder only — a caller must consult `$activeKnown` and never persist a
     *                          "false" it did not actually receive (see {@see \App\Order58\Contract\ActiveFlag}).
     * @param bool $activeKnown Whether `account.active` was present and a recognised value.
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $company,
        public bool $active,
        public bool $activeKnown,
        public string $syncHash,
        public array $raw,
    ) {}
}
