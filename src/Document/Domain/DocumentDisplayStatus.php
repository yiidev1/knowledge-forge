<?php

declare(strict_types=1);

namespace App\Document\Domain;

/**
 * The simplified, admin-facing status of a document. The backend keeps the full {@see DocumentStatus}
 * lifecycle (uploaded/queued/processing/indexing/ready/failed/deleted); this collapses it to the four words
 * a normal admin needs. A disabled document reads as "Disabled" regardless of where it is in the lifecycle.
 */
enum DocumentDisplayStatus: string
{
    case Ready = 'ready';
    case Processing = 'processing';
    case Failed = 'failed';
    case Disabled = 'disabled';

    public static function for(DocumentStatus $status, bool $isEnabled): self
    {
        if (!$isEnabled) {
            return self::Disabled;
        }

        return match ($status) {
            DocumentStatus::Ready => self::Ready,
            DocumentStatus::Failed => self::Failed,
            // uploaded, queued, processing, indexing (deleted never reaches the list) all read as "Processing".
            default => self::Processing,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Processing => 'Processing',
            self::Failed => 'Failed',
            self::Disabled => 'Disabled',
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
            self::Disabled => 'muted',
        };
    }
}
