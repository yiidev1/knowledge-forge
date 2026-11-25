<?php

declare(strict_types=1);

namespace App\Order58\Web\RecentCalls;

use App\Integration\Order58Recording\RecordingChannel;

use function array_key_exists;
use function is_int;
use function is_string;
use function preg_match;

/**
 * The Recent Calls page's settings: which stores it offers, how many calls it lists, the `company` each
 * store's recordings are fetched with, and what the three recording columns are called. **The one place
 * to change any of them.** The provider's URLs, channel suffixes and timeouts are not here: they belong to
 * the shared client (`ChannelRequestMapping`, `RecordingChannel`, `ChannelApiProbe`), which every other
 * recording feature uses too.
 *
 * ## Only these stores
 *
 * The dropdown lists exactly these accounts and the server refuses any other — a store id is read from
 * the request, so the list is enforced here rather than trusted from the page. Adding a store is adding
 * one line below. No other feature reads this list, so the stores offered elsewhere do not change.
 *
 * ## Why `company` is set per store, and why it is `SWCC`
 *
 * The provider has not documented what `company` means. What is known, from the provider itself:
 *
 *  - every Case Order recording link the provider has sent for these two stores (and for all 52 stores
 *    in the local mirror) carries `company=SWCC`, and those links play on the Manage Orders page;
 *  - the Order API viewer measured on the live provider (27 October 2026) that `company` is required and
 *    sent the same constant.
 *
 * The Calls importer sends each store's own mirrored code instead (`RecordingCompanyResolver`), and the
 * project handover notes that whether per-store codes work is unconfirmed. So this page uses the value
 * the provider itself has been seen to accept — but per store, so that if a store turns out to need a
 * different code it is a one-value change here, verified on the live server.
 */
final class RecentCallsSettings
{
    /** Fixed for this page: the latest 25 calls, never more. */
    public const LIMIT = 25;

    /** What the dropdown opens on. */
    public const DEFAULT_STORE = 1731;

    /**
     * Account id => what the dropdown says, and the `company` value its recordings are fetched with.
     *
     * @var array<int, array{name: string, company: string}>
     */
    private const STORES = [
        1731 => ['name' => 'Best Chinese Cuisine II', 'company' => 'SWCC'],
        91 => ['name' => 'GREAT WALL', 'company' => 'SWCC'],
    ];

    /**
     * The three recording columns, in table order: channel => column heading.
     *
     * Caller and callee are labelled Customer and Agent here because this page shows calls taken by the
     * store — the customer rings in, the agent answers — the same reading the rest of the application
     * gives CALLER and CALLEE recordings. The provider's own word stays in brackets.
     *
     * @return array<string, string>
     */
    public static function channelColumns(): array
    {
        return [
            RecordingChannel::Mixed->value => 'Mix Audio',
            RecordingChannel::Caller->value => 'Customer Audio (Caller)',
            RecordingChannel::Callee->value => 'Agent Audio (Callee)',
        ];
    }

    /** @return array<int, string> account id => store name, in dropdown order */
    public static function options(): array
    {
        $options = [];

        foreach (self::STORES as $accountId => $store) {
            $options[$accountId] = $store['name'];
        }

        return $options;
    }

    /**
     * The account id a request names, or null when it is not one of this page's stores.
     *
     * Digits only, anchored with `\A`/`\z`, then checked against the list: a value the page did not
     * offer is refused whatever its shape.
     */
    public static function find(mixed $raw): ?int
    {
        if (is_int($raw)) {
            $raw = (string) $raw;
        }

        if (!is_string($raw) || preg_match('/\A\d{1,9}\z/', $raw) !== 1) {
            return null;
        }

        $accountId = (int) $raw;

        return array_key_exists($accountId, self::STORES) ? $accountId : null;
    }

    /** The name the dropdown shows. Only for an id {@see find()} returned. */
    public static function name(int $accountId): string
    {
        return self::STORES[$accountId]['name'] ?? '';
    }

    /** The `company` this store's recordings are fetched with. Only for an id {@see find()} returned. */
    public static function company(int $accountId): string
    {
        return self::STORES[$accountId]['company'] ?? '';
    }
}
