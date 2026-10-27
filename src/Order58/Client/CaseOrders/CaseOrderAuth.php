<?php

declare(strict_types=1);

namespace App\Order58\Client\CaseOrders;

/**
 * How the Case Order endpoint is authenticated.
 *
 * ## Why this is a setting and not a decision
 *
 * The integration note supplied with this endpoint authenticates with `?access-token=` in the query
 * string. A Postman capture against the same endpoint authenticates with `Authorization: Bearer` and
 * is answered `200 OK`. Both are evidence, they disagree, and only the provider can settle which is
 * contractual rather than merely currently tolerated.
 *
 * So neither is hard-coded. The query parameter is the default because it is what the provider's own
 * example does, and the header is one `.env` value away for the day they say otherwise. A wrong guess
 * here costs a day's allowance to discover, which is the thing worth designing against.
 */
enum CaseOrderAuth: string
{
    /** `?access-token=…`, as the provider's PHP example does it. The default. */
    case Query = 'query';

    /** `Authorization: Bearer …`, as the Postman capture does it. */
    case Bearer = 'bearer';

    /**
     * Read from configuration, falling back to the documented default.
     *
     * An unrecognised value falls back rather than throwing: a typo in `.env` should not take the
     * page down, and the fallback is the mode the provider wrote down.
     */
    public static function fromConfig(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Query;
    }
}
