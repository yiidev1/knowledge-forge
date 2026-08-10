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

    /**
     * Order58 rule projections (store-local, global, or legacy common). Used by chat retrieval/citation scope —
     * orthogonal to {@see isQualifyingChatContent()}, which only decides whether a base is *enabled* for Store Chat.
     */
    public function isOrder58RuleProjection(): bool
    {
        return $this === self::Order58RuleStore
            || $this === self::Order58RuleGlobal
            || $this === self::Order58RuleCommon;
    }

    /**
     * Whether a document of this source type, once usable/indexed, is genuine *answerable* content that can make
     * a knowledge base chattable on its own.
     *
     * Excluded (NOT qualifying by themselves):
     * - the background {@see Order58StoreProfile} snapshot (store metadata, not answerable content), and
     * - the rule projections {@see Order58RuleStore}/{@see Order58RuleGlobal}/{@see Order58RuleCommon} — rules
     *   refine *how* genuine content is answered; a base whose only ready content is rules must not enable chat.
     *
     * Everything else — Order58 knowledge records and admin uploads (manual text, PDF, text file, image) — is
     * genuine content and qualifies. This is the ONE definition of "qualifying chat content"; the SQL eligibility
     * fragment and the PHP availability policy both derive their exclusion from it, so they can never diverge.
     *
     * Availability qualification is deliberately separate from retrieval/citation eligibility
     * ({@see \App\Chat\Domain\ChatRetrievalScope}): a store profile may still be a valid citation source even
     * though it never enables Store Chat by itself.
     */
    public function isQualifyingChatContent(): bool
    {
        return match ($this) {
            self::Order58StoreProfile,
            self::Order58RuleStore,
            self::Order58RuleGlobal,
            self::Order58RuleCommon => false,
            default => true,
        };
    }

    /**
     * The raw `source_type` values that are NOT qualifying chat content (the store profile + the rule
     * projections), for reuse by the PHP policy and the SQL eligibility fragment. Derived from
     * {@see isQualifyingChatContent()} so the exclusion is defined in exactly one place.
     *
     * @return list<string>
     */
    public static function nonQualifyingChatContentValues(): array
    {
        return array_values(array_map(
            static fn(self $type): string => $type->value,
            array_filter(self::cases(), static fn(self $type): bool => !$type->isQualifyingChatContent()),
        ));
    }
}
