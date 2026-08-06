<?php

declare(strict_types=1);

namespace App\Chat\Domain;

/**
 * Where a persisted assistant answer came from, for the chat-source audit. Derived at answer time from the
 * winning citation and the retrieval stage that produced the grounded answer.
 */
enum AnswerSource: string
{
    case StoreKnowledge = 'store_knowledge';
    case StoreRule = 'store_rule';
    case GlobalRule = 'global_rule';
    // Legacy: earlier answers persisted the hidden-KB stage as `common_rule` before it became the
    // all-approved-rules global fallback. Kept so historic rows still map to a known case.
    case CommonRule = 'common_rule';
    case Fallback = 'fallback';
}
