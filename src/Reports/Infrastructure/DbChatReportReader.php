<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure;

use App\Reports\Contract\ChatReportReaderInterface;
use App\Reports\Domain\AgentUsageRow;
use App\Reports\Domain\AnswerStatusFilter;
use App\Reports\Domain\ChatReportQuery;
use App\Reports\Domain\ChatReportResult;
use App\Reports\Domain\ChatReportRow;
use App\Reports\Domain\ChatReportSummary;
use App\Reports\Domain\ChatTypeFilter;
use App\Reports\Domain\FeedbackFilter;
use App\Reports\Domain\RatingFilter;
use App\Reports\Domain\StoreUsageRow;
use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\Shared\Infrastructure\Db\DbDateTime;
use Yiisoft\Db\Connection\ConnectionInterface;

use function array_values;
use function count;
use function implode;
use function is_array;
use function json_decode;
use function max;
use function trim;

/**
 * MySQL read model for the admin chat report.
 *
 * Three rules shape every query here:
 *
 * 1. **The date window filters the question, not the answer.** Once a question is inside the range its
 *    current answer joins whatever its own timestamp is. Filtering both would detach answers written seconds
 *    after midnight from the questions they answer, silently losing their rating and grounding status at
 *    every boundary.
 * 2. **Chat type comes from `knowledge_bases.purpose`, never from `answer_source`.** A live row carries
 *    `answer_source = 'global_rule'` inside a store-purpose base, left from before the surfaces were split;
 *    trusting the answer would misfile the whole conversation.
 * 3. **The agent mirror is a LEFT JOIN on `admin_id`.** `conversations.participant_id` holds the Order58
 *    `admin_id`, not `order58_agents.id`, and there is deliberately no foreign key — an agent can chat
 *    before the agents sync has run, and their activity must still be reported.
 *
 * Only user input is bound as a parameter; every interpolated fragment comes from a closed enum or an
 * integer cast, so no request text reaches the SQL text.
 */
final readonly class DbChatReportReader implements ChatReportReaderInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function summary(ChatReportQuery $query): ChatReportSummary
    {
        [$where, $params] = $this->baseWhere($query);

        $sql = $this->pairsCte($where)
            . ' SELECT'
            . ' COUNT(*) [[questions]],'
            . ' COUNT(DISTINCT [[agent_admin_id]]) [[agents]],'
            . ' SUM([[answer_id]] IS NOT NULL) [[answers]],'
            . ' SUM([[answer_id]] IS NULL) [[unanswered]],'
            . ' SUM([[score]] IS NOT NULL) [[rated]],'
            . ' SUM([[answer_id]] IS NOT NULL AND [[score]] IS NULL) [[unrated]],'
            . ' AVG([[score]]) [[avg_score]],'
            . ' SUM([[score]] BETWEEN 1 AND 3) [[low]],'
            . ' SUM([[comment]] IS NOT NULL) [[comments]],'
            . ' SUM([[is_rule_chat]] = 0) [[store_questions]],'
            . ' SUM([[is_rule_chat]] = 1) [[rule_questions]],'
            . ' SUM([[answer_id]] IS NOT NULL AND [[is_grounded]] = 1) [[grounded]],'
            . ' SUM([[answer_id]] IS NOT NULL AND [[is_grounded]] = 0) [[fallback]],'
            . ' AVG([[response_seconds]]) [[avg_response]]'
            . ' FROM [[pairs]]';

        $row = $this->connection->createCommand($sql, $params)->queryOne();
        $row = is_array($row) ? $row : [];

        $sessions = $this->sessionTotals($query);

        return new ChatReportSummary(
            activeAgents: (int) ($row['agents'] ?? 0),
            questions: (int) ($row['questions'] ?? 0),
            answers: (int) ($row['answers'] ?? 0),
            unansweredQuestions: (int) ($row['unanswered'] ?? 0),
            ratedAnswers: (int) ($row['rated'] ?? 0),
            unratedAnswers: (int) ($row['unrated'] ?? 0),
            averageRating: $this->nullableFloat($row['avg_score'] ?? null),
            lowRatings: (int) ($row['low'] ?? 0),
            comments: (int) ($row['comments'] ?? 0),
            storeQuestions: (int) ($row['store_questions'] ?? 0),
            ruleQuestions: (int) ($row['rule_questions'] ?? 0),
            groundedAnswers: (int) ($row['grounded'] ?? 0),
            fallbackAnswers: (int) ($row['fallback'] ?? 0),
            sessions: $sessions['sessions'],
            chatSeconds: $sessions['seconds'],
            averageResponseSeconds: $this->nullableFloat($row['avg_response'] ?? null),
        );
    }

    public function agentUsage(ChatReportQuery $query): array
    {
        [$where, $params] = $this->baseWhere($query);

        $sql = $this->pairsCte($where) . ', ' . $this->sessionSpansCte($query, $params)
            . ' SELECT [[p]].[[agent_admin_id]] [[agent_admin_id]],'
            . ' MAX([[a]].[[first_name]]) [[first_name]], MAX([[a]].[[last_name]]) [[last_name]],'
            . ' MAX([[a]].[[username]]) [[username]],'
            . ' COUNT(*) [[questions]],'
            . ' SUM([[p]].[[is_rule_chat]] = 0) [[store_questions]],'
            . ' SUM([[p]].[[is_rule_chat]] = 1) [[rule_questions]],'
            . ' SUM([[p]].[[answer_id]] IS NOT NULL) [[answers]],'
            . ' SUM([[p]].[[score]] IS NOT NULL) [[rated]],'
            . ' AVG([[p]].[[score]]) [[avg_rating]],'
            . ' SUM([[p]].[[score]] BETWEEN 1 AND 3) [[low_ratings]],'
            . ' SUM([[p]].[[comment]] IS NOT NULL) [[comments]],'
            . ' MAX([[p]].[[asked_at]]) [[last_activity]],'
            . ' MAX([[l]].[[last_login_at]]) [[last_login]],'
            . ' COALESCE(MAX([[s]].[[sessions]]), 0) [[sessions]],'
            . ' COALESCE(MAX([[s]].[[seconds]]), 0) [[chat_seconds]],'
            . ' COALESCE(MAX([[a]].[[username]]), CAST([[p]].[[agent_admin_id]] AS CHAR)) [[agent_name]]'
            . ' FROM [[pairs]] [[p]]'
            // No FK and no guarantee the mirror has seen this agent — LEFT, always.
            . ' LEFT JOIN {{%order58_agents}} [[a]] ON [[a]].[[admin_id]] = [[p]].[[agent_admin_id]]'
            . ' LEFT JOIN {{%agent_login_activity}} [[l]] ON [[l]].[[agent_admin_id]] = [[p]].[[agent_admin_id]]'
            . ' LEFT JOIN [[spans]] [[s]] ON [[s]].[[agent_admin_id]] = [[p]].[[agent_admin_id]]'
            . ' GROUP BY [[p]].[[agent_admin_id]]'
            . ' ORDER BY ' . $query->agentSort->orderBy();

        $rows = [];
        foreach ($this->connection->createCommand($sql, $params)->queryAll() as $row) {
            $rows[] = new AgentUsageRow(
                agentAdminId: (int) $row['agent_admin_id'],
                agentName: $this->displayName($row['first_name'] ?? null, $row['last_name'] ?? null),
                agentUsername: $this->nullableString($row['username'] ?? null),
                questions: (int) $row['questions'],
                storeQuestions: (int) $row['store_questions'],
                ruleQuestions: (int) $row['rule_questions'],
                answers: (int) $row['answers'],
                ratedAnswers: (int) $row['rated'],
                averageRating: $this->nullableFloat($row['avg_rating'] ?? null),
                lowRatings: (int) $row['low_ratings'],
                comments: (int) $row['comments'],
                sessions: (int) $row['sessions'],
                chatSeconds: (int) $row['chat_seconds'],
                lastActivityAt: DbDateTime::parseNullable($this->nullableString($row['last_activity'] ?? null)),
                lastLoginAt: DbDateTime::parseNullable($this->nullableString($row['last_login'] ?? null)),
            );
        }

        return $rows;
    }

    public function storeUsage(ChatReportQuery $query, int $limit = 50): array
    {
        [$where, $params] = $this->baseWhere($query);
        $limit = max(1, $limit);

        $sql = $this->pairsCte($where)
            . ' SELECT [[knowledge_base_id]], MAX([[store_name]]) [[store_name]],'
            . ' MAX([[is_rule_chat]]) [[is_rule_chat]],'
            . ' COUNT(*) [[questions]],'
            . ' COUNT(DISTINCT [[agent_admin_id]]) [[agents]],'
            . ' SUM([[score]] IS NOT NULL) [[rated]],'
            . ' AVG([[score]]) [[avg_rating]],'
            . ' SUM([[score]] BETWEEN 1 AND 3) [[low_ratings]],'
            . ' SUM([[answer_id]] IS NOT NULL AND [[is_grounded]] = 0) [[fallback]],'
            . ' MAX([[asked_at]]) [[last_activity]]'
            . ' FROM [[pairs]]'
            . ' GROUP BY [[knowledge_base_id]]'
            . " ORDER BY [[questions]] DESC, [[knowledge_base_id]] ASC LIMIT $limit";

        $rows = [];
        foreach ($this->connection->createCommand($sql, $params)->queryAll() as $row) {
            $rows[] = new StoreUsageRow(
                knowledgeBaseId: (int) $row['knowledge_base_id'],
                storeName: (string) $row['store_name'],
                chatType: ((int) $row['is_rule_chat']) === 1 ? ChatTypeFilter::Rule : ChatTypeFilter::Store,
                questions: (int) $row['questions'],
                uniqueAgents: (int) $row['agents'],
                ratedAnswers: (int) $row['rated'],
                averageRating: $this->nullableFloat($row['avg_rating'] ?? null),
                lowRatings: (int) $row['low_ratings'],
                fallbackAnswers: (int) $row['fallback'],
                lastActivityAt: DbDateTime::parseNullable($this->nullableString($row['last_activity'] ?? null)),
            );
        }

        return $rows;
    }

    public function list(ChatReportQuery $query): ChatReportResult
    {
        [$where, $params] = $this->baseWhere($query);

        // The free-text search lives here and nowhere else: it must not reshape the headline figures.
        $search = trim($query->search);
        $filter = '';
        if ($search !== '') {
            $filter = ' WHERE ([[question]] LIKE :search OR [[answer]] LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $cte = $this->pairsCte($where);

        $total = (int) $this->connection
            ->createCommand($cte . ' SELECT COUNT(*) FROM [[pairs]]' . $filter, $params)
            ->queryScalar();

        $limit = max(1, $query->perPage);
        $offset = max(0, $query->offset());

        $sql = $cte
            . ' SELECT [[p]].*, [[a]].[[first_name]] [[first_name]], [[a]].[[last_name]] [[last_name]],'
            . ' [[a]].[[username]] [[username]]'
            . ' FROM [[pairs]] [[p]]'
            . ' LEFT JOIN {{%order58_agents}} [[a]] ON [[a]].[[admin_id]] = [[p]].[[agent_admin_id]]'
            . ($filter === '' ? '' : ' WHERE ([[p]].[[question]] LIKE :search OR [[p]].[[answer]] LIKE :search)')
            . " ORDER BY [[p]].[[asked_at]] DESC, [[p]].[[question_id]] DESC LIMIT $limit OFFSET $offset";

        $items = [];
        foreach ($this->connection->createCommand($sql, $params)->queryAll() as $row) {
            $answerId = $row['answer_id'] === null ? null : (int) $row['answer_id'];

            $items[] = new ChatReportRow(
                questionId: (int) $row['question_id'],
                askedAt: DbDateTime::parse((string) $row['asked_at']),
                agentAdminId: (int) $row['agent_admin_id'],
                agentName: $this->displayName($row['first_name'] ?? null, $row['last_name'] ?? null),
                agentUsername: $this->nullableString($row['username'] ?? null),
                chatType: ((int) $row['is_rule_chat']) === 1 ? ChatTypeFilter::Rule : ChatTypeFilter::Store,
                storeName: $this->nullableString($row['store_name'] ?? null),
                question: (string) $row['question'],
                answerId: $answerId,
                answer: $this->nullableString($row['answer'] ?? null),
                isGrounded: ((int) ($row['is_grounded'] ?? 0)) === 1,
                score: $row['score'] === null ? null : (int) $row['score'],
                dismissed: $this->nullableString($row['dismissed_at'] ?? null) !== null,
                comment: $this->nullableString($row['comment'] ?? null),
                responseSeconds: $row['response_seconds'] === null ? null : (int) $row['response_seconds'],
                citationCount: $this->citationCount($row['citations_json'] ?? null),
                questionEdited: ((int) ($row['edit_count'] ?? 0)) > 0,
            );
        }

        return new ChatReportResult($items, $total, $query->page, $query->perPage);
    }

    public function agentOptions(): array
    {
        $sql = 'SELECT DISTINCT [[c]].[[participant_id]] [[id]],'
            . ' [[a]].[[first_name]] [[first_name]], [[a]].[[last_name]] [[last_name]],'
            . ' [[a]].[[username]] [[username]]'
            . ' FROM {{%conversations}} [[c]]'
            . ' LEFT JOIN {{%order58_agents}} [[a]] ON [[a]].[[admin_id]] = [[c]].[[participant_id]]'
            . " WHERE [[c]].[[participant_type]] = 'agent'"
            . ' ORDER BY [[username]] ASC, [[id]] ASC';

        $options = [];
        foreach ($this->connection->createCommand($sql)->queryAll() as $row) {
            $id = (int) $row['id'];
            $name = $this->displayName($row['first_name'] ?? null, $row['last_name'] ?? null);
            $username = $this->nullableString($row['username'] ?? null);

            $label = $name ?? $username ?? ('Agent #' . $id);
            if ($name !== null && $username !== null) {
                $label = $name . ' (' . $username . ')';
            }

            $options[] = ['id' => $id, 'label' => $label];
        }

        return $options;
    }

    public function storeOptions(): array
    {
        $sql = 'SELECT DISTINCT [[kb]].[[id]] [[id]], [[kb]].[[name]] [[name]]'
            . ' FROM {{%conversations}} [[c]]'
            . ' INNER JOIN {{%knowledge_bases}} [[kb]] ON [[kb]].[[id]] = [[c]].[[knowledge_base_id]]'
            . " WHERE [[c]].[[participant_type]] = 'agent'"
            . ' ORDER BY [[kb]].[[name]] ASC';

        $options = [];
        foreach ($this->connection->createCommand($sql)->queryAll() as $row) {
            $options[] = ['id' => (int) $row['id'], 'label' => (string) $row['name']];
        }

        return $options;
    }

    // ------------------------------------------------------------------ query building

    /**
     * The core CTE: one row per agent question in range, with the answer that currently stands for it.
     *
     * The answer join is `superseded_at IS NULL`, so a replaced answer never appears and never counts —
     * while the question it belongs to still does. `is_rule_chat` is derived once here so every caller
     * classifies identically.
     */
    private function pairsCte(string $where): string
    {
        $rulePurpose = EnsureCommonRulesKnowledgeBaseService::PURPOSE;

        return 'WITH [[pairs]] AS ('
            . ' SELECT [[q]].[[id]] [[question_id]], [[q]].[[created_at]] [[asked_at]],'
            . ' [[q]].[[content]] [[question]], [[q]].[[edit_count]] [[edit_count]],'
            . ' [[c]].[[participant_id]] [[agent_admin_id]],'
            . ' [[kb]].[[id]] [[knowledge_base_id]], [[kb]].[[name]] [[store_name]],'
            . " ([[kb]].[[purpose]] = '$rulePurpose') [[is_rule_chat]],"
            . ' [[ans]].[[id]] [[answer_id]], [[ans]].[[content]] [[answer]],'
            // Compared to 1 in SQL rather than cast in PHP: `is_grounded` is BIT(1), and PDO hands a BIT
            // back as a raw byte that `(int)` reads as 0 — the same trap the admin_users migration warns
            // about. Comparing here yields a plain 0/1 that hydrates predictably.
            . ' ([[ans]].[[is_grounded]] = 1) [[is_grounded]],'
            . ' [[ans]].[[citations_json]] [[citations_json]],'
            . ' TIMESTAMPDIFF(SECOND, [[q]].[[created_at]], [[ans]].[[created_at]]) [[response_seconds]],'
            . ' [[sc]].[[score]] [[score]], [[sc]].[[feedback_comment]] [[comment]],'
            . ' [[sc]].[[dismissed_at]] [[dismissed_at]]'
            . ' FROM {{%messages}} [[q]]'
            . ' INNER JOIN {{%conversations}} [[c]] ON [[c]].[[id]] = [[q]].[[conversation_id]]'
            . ' INNER JOIN {{%knowledge_bases}} [[kb]] ON [[kb]].[[id]] = [[c]].[[knowledge_base_id]]'
            // The current answer only. No date bound here on purpose — see the class docblock.
            . ' LEFT JOIN {{%messages}} [[ans]] ON [[ans]].[[reply_to_message_id]] = [[q]].[[id]]'
            . "   AND [[ans]].[[role]] = 'assistant' AND [[ans]].[[superseded_at]] IS NULL"
            // The rating belongs to the conversation's own agent, never to another participant.
            . ' LEFT JOIN {{%chat_answer_scores}} [[sc]] ON [[sc]].[[message_id]] = [[ans]].[[id]]'
            . "   AND [[sc]].[[participant_type]] = 'agent'"
            . '   AND [[sc]].[[participant_id]] = [[c]].[[participant_id]]'
            . $where
            . ')';
    }

    /**
     * @return array{0: string, 1: array<non-empty-string, string>}
     */
    private function baseWhere(ChatReportQuery $query): array
    {
        $conditions = [
            "[[q]].[[role]] = 'user'",
            "[[c]].[[participant_type]] = 'agent'",
            '[[q]].[[created_at]] >= :from',
            '[[q]].[[created_at]] < :to',
        ];

        $params = [
            ':from' => DbDateTime::format($query->range->startUtc),
            ':to' => DbDateTime::format($query->range->endUtcExclusive),
        ];

        $rulePurpose = EnsureCommonRulesKnowledgeBaseService::PURPOSE;
        match ($query->chatType) {
            ChatTypeFilter::All => null,
            ChatTypeFilter::Store => $conditions[] = "[[kb]].[[purpose]] <> '$rulePurpose'",
            ChatTypeFilter::Rule => $conditions[] = "[[kb]].[[purpose]] = '$rulePurpose'",
        };

        if ($query->agentAdminId !== null) {
            $conditions[] = '[[c]].[[participant_id]] = :agent';
            $params[':agent'] = (string) $query->agentAdminId;
        }

        if ($query->knowledgeBaseId !== null) {
            $conditions[] = '[[kb]].[[id]] = :kb';
            $params[':kb'] = (string) $query->knowledgeBaseId;
        }

        match ($query->status) {
            AnswerStatusFilter::All => null,
            AnswerStatusFilter::Grounded => $conditions[] = '[[ans]].[[id]] IS NOT NULL AND [[ans]].[[is_grounded]] = 1',
            AnswerStatusFilter::Fallback => $conditions[] = '[[ans]].[[id]] IS NOT NULL AND [[ans]].[[is_grounded]] = 0',
            AnswerStatusFilter::Unanswered => $conditions[] = '[[ans]].[[id]] IS NULL',
        };

        $range = $query->rating->scoreRange();
        if ($range !== null) {
            $conditions[] = '[[sc]].[[score]] BETWEEN ' . $range[0] . ' AND ' . $range[1];
        } elseif ($query->rating === RatingFilter::Rated) {
            $conditions[] = '[[sc]].[[score]] IS NOT NULL';
        } elseif ($query->rating === RatingFilter::Unrated) {
            // Unrated means an answer exists but carries no score. A dismissal is unrated; an unanswered
            // question is not an unrated answer.
            $conditions[] = '[[ans]].[[id]] IS NOT NULL AND [[sc]].[[score]] IS NULL';
        }

        match ($query->feedback) {
            FeedbackFilter::All => null,
            FeedbackFilter::WithComment => $conditions[] = '[[sc]].[[feedback_comment]] IS NOT NULL',
            FeedbackFilter::WithoutComment => $conditions[] = '[[sc]].[[feedback_comment]] IS NULL',
        };

        return [' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * Sessionisation, per agent, across every chat they used.
     *
     * A new session starts where the gap since the previous message exceeds the threshold; a running sum of
     * those flags numbers the sessions, and each session's span is last activity minus first. Grouping by
     * agent alone (not by knowledge base) is what keeps the total honest: two stores used in the same ten
     * minutes is one session, so the reported time can never exceed the elapsed time it happened in.
     *
     * The span measures first message to last message. Reading time before the first question and after the
     * final answer is not observable from message rows and is deliberately not invented.
     *
     * @param array<non-empty-string, string> $params
     */
    private function sessionSpansCte(ChatReportQuery $query, array &$params): string
    {
        // Compared in seconds, not minutes: TIMESTAMPDIFF(MINUTE, …) truncates, so a gap of 30m55s would
        // read as exactly 30 and fail a "> 30" test — two clearly separate visits would merge into one.
        $gap = ChatReportQuery::SESSION_GAP_MINUTES * 60;
        $params[':session_from'] = DbDateTime::format($query->range->startUtc);
        $params[':session_to'] = DbDateTime::format($query->range->endUtcExclusive);

        $agentFilter = '';
        if ($query->agentAdminId !== null) {
            $agentFilter = ' AND [[c]].[[participant_id]] = :agent';
        }

        return '[[activity]] AS ('
            . ' SELECT [[c]].[[participant_id]] [[agent_admin_id]], [[m]].[[created_at]] [[ts]],'
            . ' CASE WHEN LAG([[m]].[[created_at]]) OVER ('
            . '   PARTITION BY [[c]].[[participant_id]] ORDER BY [[m]].[[created_at]], [[m]].[[id]]'
            . " ) IS NULL THEN 1 WHEN TIMESTAMPDIFF(SECOND, LAG([[m]].[[created_at]]) OVER ("
            . '   PARTITION BY [[c]].[[participant_id]] ORDER BY [[m]].[[created_at]], [[m]].[[id]]'
            . " ), [[m]].[[created_at]]) > $gap THEN 1 ELSE 0 END [[is_new]]"
            . ' FROM {{%messages}} [[m]]'
            . ' INNER JOIN {{%conversations}} [[c]] ON [[c]].[[id]] = [[m]].[[conversation_id]]'
            . " WHERE [[c]].[[participant_type]] = 'agent'"
            . '   AND [[m]].[[created_at]] >= :session_from AND [[m]].[[created_at]] < :session_to'
            . '   AND [[m]].[[superseded_at]] IS NULL'
            . $agentFilter
            . '), [[numbered]] AS ('
            . ' SELECT [[agent_admin_id]], [[ts]],'
            . ' SUM([[is_new]]) OVER ('
            . '   PARTITION BY [[agent_admin_id]] ORDER BY [[ts]] ROWS UNBOUNDED PRECEDING'
            . ' ) [[session_no]]'
            . ' FROM [[activity]]'
            . '), [[per_session]] AS ('
            . ' SELECT [[agent_admin_id]], [[session_no]],'
            . ' TIMESTAMPDIFF(SECOND, MIN([[ts]]), MAX([[ts]])) [[span]]'
            . ' FROM [[numbered]] GROUP BY [[agent_admin_id]], [[session_no]]'
            . '), [[spans]] AS ('
            . ' SELECT [[agent_admin_id]], COUNT(*) [[sessions]], SUM([[span]]) [[seconds]]'
            . ' FROM [[per_session]] GROUP BY [[agent_admin_id]]'
            . ')';
    }

    /**
     * @return array{sessions: int, seconds: int}
     */
    private function sessionTotals(ChatReportQuery $query): array
    {
        // The spans CTE references :agent when an agent filter is active, so it must be bound here too —
        // this path does not go through baseWhere().
        $params = [];
        if ($query->agentAdminId !== null) {
            $params[':agent'] = (string) $query->agentAdminId;
        }

        $sql = 'WITH ' . $this->sessionSpansCte($query, $params)
            . ' SELECT COALESCE(SUM([[sessions]]), 0) [[sessions]], COALESCE(SUM([[seconds]]), 0) [[seconds]]'
            . ' FROM [[spans]]';

        $row = $this->connection->createCommand($sql, $params)->queryOne();
        $row = is_array($row) ? $row : [];

        return [
            'sessions' => (int) ($row['sessions'] ?? 0),
            'seconds' => (int) ($row['seconds'] ?? 0),
        ];
    }

    // ------------------------------------------------------------------ hydration helpers

    private function displayName(mixed $first, mixed $last): ?string
    {
        $name = trim(trim((string) ($first ?? '')) . ' ' . trim((string) ($last ?? '')));

        return $name === '' ? null : $name;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = (string) $value;

        return $string === '' ? null : $string;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * How many sources the answer cited. Counting rather than returning them keeps file ids out of the
     * report entirely.
     */
    private function citationCount(mixed $json): int
    {
        if (!is_string($json) || $json === '') {
            return 0;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? count(array_values($decoded)) : 0;
    }
}
