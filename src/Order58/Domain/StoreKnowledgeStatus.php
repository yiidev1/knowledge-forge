<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use App\KnowledgeBase\Domain\VectorStoreStatus;

/**
 * The single, friendly knowledge status shown on a store card — the only status a normal admin sees. It
 * hides the underlying technical axes (source-active, agent-enabled, vector-store state, per-document
 * lifecycle) behind one word.
 *
 * Priority (highest first): if any enabled document is already indexed the store is Ready (chat has usable
 * knowledge even if other documents are still processing); otherwise if anything is being prepared it is
 * Processing; otherwise if something failed it is Failed; otherwise there is No knowledge.
 */
enum StoreKnowledgeStatus: string
{
    case Ready = 'ready';
    case Processing = 'processing';
    case Failed = 'failed';
    case NoKnowledge = 'no_knowledge';

    /**
     * @param bool $hasReadyDocument   an enabled document with status = ready exists
     * @param bool $hasDocumentInProgress an enabled document is uploaded/queued/processing/indexing
     * @param bool $hasFailedDocument   an enabled document has failed
     */
    public static function fromFlags(
        bool $hasReadyDocument,
        bool $hasDocumentInProgress,
        bool $hasFailedDocument,
        VectorStoreStatus $vectorStoreStatus,
    ): self {
        if ($hasReadyDocument) {
            return self::Ready;
        }

        $preparing = $vectorStoreStatus === VectorStoreStatus::Pending
            || $vectorStoreStatus === VectorStoreStatus::Provisioning;
        if ($hasDocumentInProgress || $preparing) {
            return self::Processing;
        }

        if ($hasFailedDocument || $vectorStoreStatus === VectorStoreStatus::Failed) {
            return self::Failed;
        }

        return self::NoKnowledge;
    }

    public static function fromRequest(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Processing => 'Processing',
            self::Failed => 'Failed',
            self::NoKnowledge => 'No knowledge',
        };
    }

    /**
     * The admin CSS badge token ({@see badge--*}).
     */
    public function badge(): string
    {
        return match ($this) {
            self::Ready => 'success',
            self::Processing => 'info',
            self::Failed => 'error',
            self::NoKnowledge => 'muted',
        };
    }
}
