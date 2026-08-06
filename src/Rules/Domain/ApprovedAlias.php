<?php

declare(strict_types=1);

namespace App\Rules\Domain;

/**
 * An approved store alias used by the matcher. For a name alias, `normalized` is the token form
 * ("moon temple"); for a `domain` alias it is the lower-cased domain ("moontemple.order58.com").
 */
final readonly class ApprovedAlias
{
    public function __construct(
        public int $storeSourceId,
        public string $alias,
        public string $normalized,
        public AliasType $type,
    ) {}
}
