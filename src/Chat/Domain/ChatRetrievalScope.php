<?php

declare(strict_types=1);

namespace App\Chat\Domain;

use App\Document\Domain\DocumentSourceType;

/**
 * Hard retrieval/citation boundary for a chat surface. Prompt wording is secondary; this scope is what
 * {@see \App\Chat\Application\Citation\CitationResolver} and grounding enforce.
 *
 * Availability ("can this KB be chatted with?") remains a separate concern — see
 * {@see DocumentSourceType::isQualifyingChatContent()} for Store Chat enablement.
 */
enum ChatRetrievalScope: string
{
    /** Store Chat: any non-rule document source may ground an answer; all Order58 rule projections are rejected. */
    case StoreKnowledge = 'store_knowledge';

    /** Rule Chat: only searchable rule projections in the hidden global rules KB may ground an answer. */
    case RuleOnly = 'rule_only';

    public function allows(DocumentSourceType $type): bool
    {
        return match ($this) {
            self::StoreKnowledge => !$type->isOrder58RuleProjection(),
            self::RuleOnly => $type === DocumentSourceType::Order58RuleGlobal
                || $type === DocumentSourceType::Order58RuleCommon,
        };
    }

    /**
     * @param string|null $sourceTypeValue Raw `documents.source_type`, or null when unknown.
     */
    public function allowsValue(?string $sourceTypeValue): bool
    {
        if ($sourceTypeValue === null || $sourceTypeValue === '') {
            return false;
        }

        $type = DocumentSourceType::tryFrom($sourceTypeValue);

        return $type !== null && $this->allows($type);
    }
}
