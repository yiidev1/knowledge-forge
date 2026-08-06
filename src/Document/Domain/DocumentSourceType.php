<?php

declare(strict_types=1);

namespace App\Document\Domain;

/**
 * Where a document came from — its provenance and routing axis, orthogonal to {@see DocumentKind} (how it
 * is ingested).
 *
 * Phase 1 uses the Order58-generated and upload types; `uploaded_text` and `manual_text` are declared here
 * so Phase 3 adds them without a migration (the `documents.source_type` column is a VARCHAR). Persisted as
 * the raw string, so a new case never needs a schema change.
 */
enum DocumentSourceType: string
{
    case Order58StoreProfile = 'order58_store_profile';
    case Order58Knowledge = 'order58_knowledge';
    case Order58RuleStore = 'order58_rule_store';
    case Order58RuleGlobal = 'order58_rule_global';
    case Order58RuleCommon = 'order58_rule_common';
    case UploadedPdf = 'uploaded_pdf';
    case UploadedImage = 'uploaded_image';
    case UploadedText = 'uploaded_text';
    case ManualText = 'manual_text';

    public function label(): string
    {
        return match ($this) {
            self::Order58StoreProfile => 'Store profile (Order58)',
            self::Order58Knowledge => 'Knowledge record (Order58)',
            self::Order58RuleStore => 'Store rule (Order58)',
            self::Order58RuleGlobal => 'Global rule (Order58)',
            self::Order58RuleCommon => 'Common rule (Order58)',
            self::UploadedPdf => 'Uploaded PDF',
            self::UploadedImage => 'Uploaded image',
            self::UploadedText => 'Uploaded text file',
            self::ManualText => 'Manual text',
        };
    }

    /**
     * Generated deterministically from an Order58 source record (not an admin upload).
     */
    public function isOrder58Generated(): bool
    {
        return $this === self::Order58StoreProfile
            || $this === self::Order58Knowledge
            || $this === self::Order58RuleStore
            || $this === self::Order58RuleGlobal
            || $this === self::Order58RuleCommon;
    }
}
