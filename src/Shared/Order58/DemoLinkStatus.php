<?php

declare(strict_types=1);

namespace App\Shared\Order58;

/**
 * Why a demo link is, or is not, available for one row.
 *
 * Each case is a different missing thing, and the page says which. "Not available" for all six would
 * leave an operator with nowhere to look: a missing phone is fixed by re-syncing the order, a missing
 * host by syncing the store, and an unmatched order id by typing a real one at upload.
 */
enum DemoLinkStatus: string
{
    case Ready = 'ready';
    case NoOrderId = 'no_order_id';
    case OrderNotFound = 'order_not_found';
    case StoreNotFound = 'store_not_found';
    case HostUnavailable = 'host_unavailable';
    case PhoneUnavailable = 'phone_unavailable';
    case Ambiguous = 'ambiguous';

    /** What the table cell shows when there is no link. */
    public function message(): string
    {
        return match ($this) {
            self::Ready => 'Open Demo URL',
            self::NoOrderId, self::OrderNotFound => 'Order not found',
            self::StoreNotFound => 'Store not found',
            self::HostUnavailable => 'Host unavailable',
            self::PhoneUnavailable => 'Phone unavailable',
            self::Ambiguous => 'Order match ambiguous',
        };
    }
}
