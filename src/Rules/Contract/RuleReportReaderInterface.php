<?php

declare(strict_types=1);

namespace App\Rules\Contract;

use App\Rules\Domain\RuleDetail;
use App\Rules\Domain\RuleReportQuery;
use App\Rules\Domain\RuleReportResult;
use App\Rules\Domain\RuleReportSummary;

/**
 * Read model for the admin rules report: the catalog summary, the paginated per-rule listing, and one rule's
 * full review detail.
 */
interface RuleReportReaderInterface
{
    public function summary(): RuleReportSummary;

    public function list(RuleReportQuery $query): RuleReportResult;

    /**
     * Full review detail for one canonical rule, or null if it does not exist.
     */
    public function findDetail(int $canonicalId): ?RuleDetail;
}
