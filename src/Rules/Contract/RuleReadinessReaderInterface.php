<?php

declare(strict_types=1);

namespace App\Rules\Contract;

use App\Rules\Domain\RuleReadinessBaseInfo;
use App\Rules\Domain\RuleReadinessQuery;
use App\Rules\Domain\RuleReadinessResult;
use App\Rules\Domain\RuleReadinessSummary;

/**
 * Read model for the operational readiness of materialized Order58 rule documents (store + global/common).
 *
 * Every figure is derived from the durable index-file snapshot in a single aggregate query (no N+1); a rule
 * document is operationally Ready only when it is enabled, not deleted, and has a completed `document_index_files`
 * row with an `openai_file_id`.
 */
interface RuleReadinessReaderInterface
{
    /**
     * Count of documents per operational status for the given search (and optional hidden-base scope). Uses the
     * exact same derivation as {@see self::list()}, so card counts match filter results.
     */
    public function summary(string $search, bool $hiddenBaseOnly = false): RuleReadinessSummary;

    /**
     * A paginated, searchable, filterable page of readiness rows, ordered by most-actionable status first.
     */
    public function list(RuleReadinessQuery $query): RuleReadinessResult;

    /**
     * Header facts about the hidden Global/Common Rules base, or null if it has not been provisioned yet.
     */
    public function hiddenBaseInfo(): ?RuleReadinessBaseInfo;
}
