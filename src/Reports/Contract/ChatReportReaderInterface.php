<?php

declare(strict_types=1);

namespace App\Reports\Contract;

use App\Reports\Domain\AgentUsageRow;
use App\Reports\Domain\ChatReportQuery;
use App\Reports\Domain\ChatReportResult;
use App\Reports\Domain\ChatReportRow;
use App\Reports\Domain\ChatReportSummary;
use App\Reports\Domain\StoreUsageRow;
use App\Reports\Domain\UsageResult;

/**
 * Admin-only read model over agent chat activity.
 *
 * A deliberately separate path from the participant-owned chat services: those exist to stop one participant
 * reading another's thread, which is exactly what a cross-agent report must do. Rather than weakening them,
 * this interface is read-only by construction — it has no write method, is reachable only from behind
 * `RequireAdminMiddleware`, and selects only reporting columns. No OpenAI file id, vector store id, storage
 * path, storage token or sync hash is ever returned.
 *
 * Every method is scoped to agent conversations and bounded by the query's UTC window.
 */
interface ChatReportReaderInterface
{
    /**
     * Headline figures. Ignores {@see ChatReportQuery::$search}, which is a detail-table filter only.
     */
    public function summary(ChatReportQuery $query): ChatReportSummary;

    /**
     * One page of agent rows, ordered by the query's agent sort and paged by its own page number — the three
     * tables page independently. Ignores {@see ChatReportQuery::$search}.
     *
     * @return UsageResult<AgentUsageRow>
     */
    public function agentUsage(ChatReportQuery $query): UsageResult;

    /**
     * One page of knowledge-base rows, ordered and paged by the query's own store sort/page.
     * Ignores {@see ChatReportQuery::$search}.
     *
     * @return UsageResult<StoreUsageRow>
     */
    public function storeUsage(ChatReportQuery $query): UsageResult;

    /**
     * One question and its current answer, in full, resolved under the same filters as every other view — a
     * record the active filters exclude is simply not found, so the detail view can never reach further than
     * the report itself.
     */
    public function findDetail(int $questionId, ChatReportQuery $query): ?ChatReportRow;

    /**
     * A page of question/answer detail rows, newest question first. This is the only method that applies
     * the free-text search.
     */
    public function list(ChatReportQuery $query): ChatReportResult;

    /**
     * Agents that appear anywhere in chat history, for the filter dropdown — not restricted to the current
     * range, so switching dates never empties the picker.
     *
     * @return list<array{id: int, label: string}>
     */
    public function agentOptions(): array;

    /**
     * Knowledge bases that appear anywhere in agent chat history, for the filter dropdown.
     *
     * @return list<array{id: int, label: string}>
     */
    public function storeOptions(): array;
}
