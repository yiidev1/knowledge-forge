<?php

declare(strict_types=1);

namespace App\Rules\Application;

use App\Order58\Domain\Order58StoreRepositoryInterface;
use App\Rules\Contract\StoreAliasRepositoryInterface;
use App\Rules\Domain\AliasType;
use DateTimeImmutable;

use function is_string;
use function mb_strtolower;
use function parse_url;
use function preg_replace;
use function trim;

use const PHP_URL_HOST;

/**
 * Seeds approved store aliases from the local store mirror: the official name, the company name (when present),
 * and a domain (when a URL/host is present in the curated snapshot). Idempotent via the UNIQUE
 * (store_source_id, normalized_alias), so it is safe to run on every sync.
 */
final readonly class StoreAliasSeeder
{
    /** Snapshot keys that may carry a store's domain/URL. */
    private const DOMAIN_KEYS = ['domain', 'host', 'website', 'url', 'site'];

    public function __construct(
        private Order58StoreRepositoryInterface $stores,
        private StoreAliasRepositoryInterface $aliases,
    ) {}

    public function seed(DateTimeImmutable $now): int
    {
        $count = 0;
        foreach ($this->stores->allMirrors() as $store) {
            $name = AliasNormalizer::normalize($store->name);
            if ($name !== '') {
                $this->aliases->upsertApproved($store->sourceId, $store->name, $name, AliasType::OfficialName, null, $now);
                $count++;
            }

            if ($store->company !== null && trim($store->company) !== '') {
                $company = AliasNormalizer::normalize($store->company);
                if ($company !== '') {
                    $this->aliases->upsertApproved($store->sourceId, $store->company, $company, AliasType::CompanyName, null, $now);
                    $count++;
                }
            }

            $domain = $this->extractDomain($store->snapshot);
            if ($domain !== null) {
                $this->aliases->upsertApproved($store->sourceId, $domain, $domain, AliasType::Domain, null, $now);
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<array-key, mixed> $snapshot
     */
    private function extractDomain(array $snapshot): ?string
    {
        foreach (self::DOMAIN_KEYS as $key) {
            $value = $snapshot[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $host = parse_url($value, PHP_URL_HOST);
            $domain = is_string($host) && $host !== '' ? $host : $value;
            // Strip a scheme-less leading "www." and any path, keep a bare lower-cased host.
            $domain = mb_strtolower(trim($domain));
            $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
            $domain = preg_replace('#/.*$#', '', $domain) ?? $domain;

            return $domain === '' ? null : $domain;
        }

        return null;
    }
}
