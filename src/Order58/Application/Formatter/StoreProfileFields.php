<?php

declare(strict_types=1);

namespace App\Order58\Application\Formatter;

/**
 * The business-relevant, agent-useful account fields Knowledge Forge selects from a store record, in a
 * fixed order. Deliberately excludes noise and anything operational or credential-ish (balances,
 * commission, card flags, print settings, internal ids). Shared by the snapshot builder and the
 * deterministic store-profile formatter so both stay in lock-step.
 *
 * @psalm-suppress UnusedClass — referenced by the mapper and formatter.
 */
final class StoreProfileFields
{
    /**
     * Ordered raw-key => human label. `name` and `active` (status) are rendered separately, first.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'company' => 'Company',
        'address' => 'Address',
        'address_2' => 'Address line 2',
        'landmark' => 'Landmark',
        'city' => 'City',
        'state' => 'State',
        'zip' => 'ZIP',
        'phone' => 'Phone',
        'email' => 'Email',
        'timezone' => 'Timezone',
        'lunch_hours' => 'Hours',
        'host' => 'Host',
        'default_line' => 'Line',
        'google_url' => 'Map',
        'description' => 'Description',
    ];
}
