<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use App\KnowledgeBase\Domain\VectorStoreStatus;
use DateTimeImmutable;

use function array_filter;
use function implode;
use function trim;

/**
 * One store row of the admin directory: the mirrored store joined to its knowledge base. The card shows only
 * {@see $knowledgeStatus} (the single friendly status) and the agent-access toggle; the remaining technical
 * fields are kept for eligibility/filtering and are not rendered on the card. A pure read model built by
 * {@see StoreDirectoryReaderInterface}.
 */
final readonly class StoreDirectoryItem
{
    public function __construct(
        public int $sourceId,
        public int $knowledgeBaseId,
        public string $slug,
        public string $name,
        public ?string $company,
        public ?string $address,
        public ?string $city,
        public ?string $state,
        public bool $sourceActive,
        public bool $agentEnabled,
        public VectorStoreStatus $vectorStoreStatus,
        public int $enabledReadyDocumentCount,
        public int $knowledgeRecordCount,
        public ?DateTimeImmutable $lastSourceSyncedAt,
        public StoreKnowledgeStatus $knowledgeStatus,
        /** Whether the store is chat-eligible by the canonical policy (SQL-mirrored). */
        public bool $chatEligible = false,
        /**
         * When not chat-eligible, the raw {@see \App\Chat\Application\ChatUnavailableReason} code
         * (source_inactive / not_provisioned / order58_not_ready / no_qualifying_document); null when eligible.
         * A raw string, so the Order58 module needs no dependency on the Chat module.
         */
        public ?string $chatReason = null,
        /** All enabled, non-deleted Order58 knowledge documents generated for this store. */
        public int $knowledgeDocsTotal = 0,
        /** Of those, how many have a completed, attached index-file snapshot (searchable). */
        public int $knowledgeDocsIndexed = 0,
    ) {}

    /**
     * A single-line address for the card, or null when nothing is known.
     */
    public function locationLine(): ?string
    {
        $parts = array_filter(
            [$this->address, $this->city, $this->state],
            static fn(?string $part): bool => $part !== null && trim($part) !== '',
        );

        return $parts === [] ? null : implode(', ', $parts);
    }
}
