<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Infrastructure;

/**
 * The SQL projection of {@see \App\Chat\Application\ChatAvailabilityPolicy} — the ONE readiness definition,
 * expressed for bounded list queries so the admin store directory, the store-chat picker and the agent
 * directory all agree with the per-request policy (the authoritative server-side gate). All fragments correlate
 * on a `knowledge_bases` row aliased `kb` and use the durable completed index-file snapshot (never
 * `documents.status`). It references only KB/document tables — no Order58-specific table — so it lives here and
 * is reused by both the Order58 admin readers and the Agent directory.
 *
 * A cross-check test asserts this SQL and the PHP policy return the same verdict for the same DB state.
 */
final class KnowledgeBaseChatEligibilitySql
{
    /** A usable qualifying (non-store-profile) document: enabled, not deleted, with a completed+attached index file. */
    public static function usableQualifyingDoc(): string
    {
        return "EXISTS (SELECT 1 FROM `documents` `d` WHERE `d`.`knowledge_base_id` = `kb`.`id`"
            . " AND `d`.`is_enabled` = 1 AND `d`.`status` <> 'deleted'"
            . " AND `d`.`source_type` <> 'order58_store_profile'"
            . ' AND EXISTS (SELECT 1 FROM `document_index_files` `f` WHERE `f`.`document_id` = `d`.`id`'
            . " AND `f`.`index_status` = 'completed' AND `f`.`openai_file_id` IS NOT NULL))";
    }

    /** A usable Order58 store-profile snapshot (same durable check, restricted to the store-profile source type). */
    public static function usableStoreProfile(): string
    {
        return "EXISTS (SELECT 1 FROM `documents` `d` WHERE `d`.`knowledge_base_id` = `kb`.`id`"
            . " AND `d`.`is_enabled` = 1 AND `d`.`status` <> 'deleted'"
            . " AND `d`.`source_type` = 'order58_store_profile'"
            . ' AND EXISTS (SELECT 1 FROM `document_index_files` `f` WHERE `f`.`document_id` = `d`.`id`'
            . " AND `f`.`index_status` = 'completed' AND `f`.`openai_file_id` IS NOT NULL))";
    }

    /**
     * A CASE returning the {@see \App\Chat\Application\ChatUnavailableReason} value, in the policy's precedence:
     * source-inactive → not-provisioned (status/vector/id) → order58 (profile+qualifying) → plain (qualifying).
     */
    public static function reasonCase(): string
    {
        $qualifying = self::usableQualifyingDoc();
        $profile = self::usableStoreProfile();

        return 'CASE'
            . " WHEN `kb`.`source_store_id` IS NOT NULL AND `kb`.`source_active` = 0 THEN 'source_inactive'"
            . " WHEN `kb`.`status` <> 'active' OR `kb`.`vector_store_status` <> 'ready'"
            . " OR `kb`.`openai_vector_store_id` IS NULL THEN 'not_provisioned'"
            . " WHEN `kb`.`source_store_id` IS NOT NULL THEN"
            . " (CASE WHEN $profile AND $qualifying THEN 'available' ELSE 'order58_not_ready' END)"
            . " ELSE (CASE WHEN $qualifying THEN 'available' ELSE 'no_qualifying_document' END)"
            . ' END';
    }

    /** Boolean chat-eligibility (ignores agent_enabled — the agent realm ANDs that separately). */
    public static function isEligible(): string
    {
        return '(' . self::reasonCase() . ") = 'available'";
    }
}
