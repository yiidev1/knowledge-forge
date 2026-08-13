<?php

declare(strict_types=1);

namespace App\Reports\Contract;

use App\Reports\Domain\AgentUsageRow;
use App\Reports\Domain\ChatReportQuery;
use App\Reports\Domain\ChatReportResult;
use App\Reports\Domain\ChatReportSummary;
use App\Reports\Domain\StoreUsageRow;

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
     * One row per agent with at least one question in range, ordered by the query's sort.
     * Ignores {@see ChatReportQuery::$search}.
     *
     * @return list<AgentUsageRow>
     */
    public function agentUsage(ChatReportQuery $query): array;

    /**
     * One row per knowledge base with at least one question in range, busiest first.
     * Ignores {@see ChatReportQuery::$search}.
     *
     * @return list<StoreUsageRow>
     */
    public function storeUsage(ChatReportQuery $query, int $limit = 50): array;

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
