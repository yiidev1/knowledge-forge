<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Domain;

/**
 * Lifecycle of a knowledge base's OpenAI vector store.
 *
 * Provisioning is a background job (Phase 6), so a freshly created knowledge base sits at `Pending`
 * until the worker creates its store. Uploads and chat stay unavailable until `Ready`.
 */
enum VectorStoreStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isReady(): bool
    {
        return $this === self::Ready;
    }

    /**
     * Short human label for the UI badge.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Provisioning pending',
            self::Provisioning => 'Provisioning',
            self::Ready => 'Ready',
            self::Failed => 'Provisioning failed',
        };
    }

    /**
     * The badge style token used by the admin CSS ({@see badge--*} classes).
     */
    public function badge(): string
    {
        return match ($this) {
            self::Pending, self::Provisioning => 'info',
            self::Ready => 'success',
            self::Failed => 'error',
        };
    }
}
