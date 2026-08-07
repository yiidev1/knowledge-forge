<?php

declare(strict_types=1);

namespace App\Chat\Application;

use App\Chat\Domain\Exception\ChatUnavailable;
use App\Document\Domain\DocumentRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\KnowledgeBase\Domain\KnowledgeBaseSourceRepositoryInterface;

/**
 * The one canonical decision for whether a knowledge base can be chatted with — shared by the admin and
 * agent surfaces and by every server-side chat operation. Availability rules live nowhere else (no
 * controller, template, agent service, or JavaScript duplicates them).
 *
 * The rule, evaluated from current database state and always scoped to the one knowledge base:
 * - if it is Order58-linked, its source store is active (an inactive store is never chattable), AND
 * - the base is active and its vector store is usable ({@see KnowledgeBase::isReadyForChat()}) with a
 *   non-null OpenAI vector-store id, AND
 * - it has at least one usable *qualifying* document — genuine answerable content, NOT the store-profile
 *   snapshot and NOT a rule projection (order58_rule_store/global/common); a base whose only ready content is
 *   rules and/or the profile is never chattable, AND
 * - if it is Order58-linked, it also has a usable Order58 store-profile snapshot.
 *
 * "Usable" is the durable last-successful snapshot ({@see DocumentRepositoryInterface::hasUsableQualifyingDocument()}),
 * so a resync in progress or a failed refresh — which leaves the previous completed vector-store file in
 * place — never makes chat unavailable. Neither the store profile nor a rule projection satisfies the
 * qualifying requirement, and an admin-disabled document never counts.
 */
final readonly class ChatAvailabilityPolicy
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private KnowledgeBaseSourceRepositoryInterface $sources,
    ) {}

    public function isAvailable(KnowledgeBase $knowledgeBase): bool
    {
        return $this->getUnavailableReason($knowledgeBase)->isAvailable();
    }

    public function getUnavailableReason(KnowledgeBase $knowledgeBase): ChatUnavailableReason
    {
        $knowledgeBaseId = $knowledgeBase->id();

        // Order58-linked iff a source-mapping resolves for this exact knowledge base (source_system='order58').
        $source = $this->sources->findOrder58SourceState($knowledgeBaseId);

        // An inactive source store is never chattable — this outranks provisioning/document state so the admin
        // and agent both see the actionable reason.
        if ($source !== null && !$source->active) {
            return ChatUnavailableReason::SourceInactive;
        }

        // Ready status + a real vector store. The null-id check defends the write-side invariant (a Ready
        // status must carry an id) rather than trusting it.
        if (!$knowledgeBase->isReadyForChat() || $knowledgeBase->openaiVectorStoreId() === null) {
            return ChatUnavailableReason::NotProvisioned;
        }

        if ($source !== null) {
            $ready = $this->documents->hasUsableOrder58StoreProfile($knowledgeBaseId)
                && $this->documents->hasUsableQualifyingDocument($knowledgeBaseId);

            return $ready ? ChatUnavailableReason::Available : ChatUnavailableReason::Order58NotReady;
        }

        return $this->documents->hasUsableQualifyingDocument($knowledgeBaseId)
            ? ChatUnavailableReason::Available
            : ChatUnavailableReason::NoQualifyingDocument;
    }

    /**
     * @throws ChatUnavailable when chat is not available for this knowledge base.
     */
    public function assertAvailable(KnowledgeBase $knowledgeBase): void
    {
        $exception = match ($this->getUnavailableReason($knowledgeBase)) {
            ChatUnavailableReason::Available => null,
            ChatUnavailableReason::SourceInactive => ChatUnavailable::sourceInactive(),
            ChatUnavailableReason::NotProvisioned => ChatUnavailable::notProvisioned(),
            ChatUnavailableReason::Order58NotReady => ChatUnavailable::order58NotReady(),
            ChatUnavailableReason::NoQualifyingDocument => ChatUnavailable::noReadyDocuments(),
        };

        if ($exception !== null) {
            throw $exception;
        }
    }
}
