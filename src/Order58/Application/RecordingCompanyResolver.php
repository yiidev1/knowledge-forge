<?php

declare(strict_types=1);

namespace App\Order58\Application;

use App\Order58\Domain\Exception\RecordingCompanyMissing;
use App\Order58\Domain\Order58StoreRepositoryInterface;

use function mb_strlen;
use function preg_match;
use function trim;

/**
 * Which `company` code a store's recordings are fetched with — the one place that decides.
 *
 * The external recording endpoint takes a `company` query parameter, and this application's answer is
 * **the selected store's own mirrored code**: store 831 (KONG'S KITCHEN) fetches with `company=KONG`.
 * It is read from `order58_stores`, never from the browser, and never from a constant.
 *
 * ## Why a resolver rather than `$store->company` at the call site
 *
 * Because the provider has not documented what `company` means on their side. The diagnostic page's
 * default is `SWCC`, which turns out to be store #21 "Order58" — Seawolf Technologies, the platform
 * vendor rather than a merchant — so there is real doubt about whether the value is per-merchant at all.
 * Reading the column directly in three places would make a future correction a three-file change with
 * one of them forgotten; reading it here makes it a one-file change, and the value that was actually
 * sent is preserved on every batch regardless.
 *
 * ## It refuses rather than substitutes
 *
 * A store with no usable code gets no import. The alternative — falling back to some other store's code,
 * or to a constant — would build a well-formed request for somebody else's audio, and the provider
 * answers that with a 200 and a WAV. A refusal an administrator can read is the only safe failure.
 */
final readonly class RecordingCompanyResolver
{
    /**
     * The provider's own field rules, as the recording request already enforces them.
     *
     * Restated as a length and a character class rather than imported from the request object: that
     * object validates a *submitted* value on its way to a URL, and this validates a *stored* one on its
     * way into a snapshot. They agree today, and a test asserts they still do.
     */
    private const MAX_LENGTH = 100;

    public function __construct(
        private Order58StoreRepositoryInterface $stores,
    ) {}

    /**
     * @throws RecordingCompanyMissing when the store is unknown, or its code is absent or unusable
     */
    public function forStore(int $storeSourceId): string
    {
        $store = $this->stores->findBySourceId($storeSourceId);

        if ($store === null) {
            throw RecordingCompanyMissing::noSuchStore($storeSourceId);
        }

        $company = trim((string) $store->company);

        if ($company === '' || mb_strlen($company) > self::MAX_LENGTH) {
            throw RecordingCompanyMissing::forStore($storeSourceId);
        }

        // A control character would ride into a query string. The mirror is written by a sync rather
        // than by a person, so this should never fire — which is exactly why it is checked here, once,
        // instead of being assumed at every call site.
        if (preg_match('/[\x00-\x1F\x7F]/', $company) === 1) {
            throw RecordingCompanyMissing::forStore($storeSourceId);
        }

        return $company;
    }
}
