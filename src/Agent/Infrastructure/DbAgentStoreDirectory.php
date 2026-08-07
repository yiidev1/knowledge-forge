<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure;

use App\Agent\Domain\AgentStore;
use App\Agent\Domain\AgentStoreDirectoryInterface;
use App\KnowledgeBase\Infrastructure\KnowledgeBaseChatEligibilitySql;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Query\QueryInterface;

use function is_array;

/**
 * MySQL-backed agent store directory over `knowledge_bases`, enriched with the mirrored store's location
 * fields for the store picker.
 *
 * A store is available to agents when it is active, `agent_enabled = 1`, its vector store is `ready`, its
 * source store is active (or it is a manually-created knowledge base with no source), and it has at least
 * one enabled, ready document to answer from. The same predicate gates the list, the by-slug lookup and the
 * count, so an agent cannot reach an unavailable store by typing its slug, and `account_id` is never
 * consulted.
 */
final readonly class DbAgentStoreDirectory implements AgentStoreDirectoryInterface
{
    private const TABLE = '{{%knowledge_bases}}';
    private const STORE_TABLE = '{{%order58_stores}}';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findAvailable(): array
    {
        $rows = $this->baseSelect()->orderBy(['kb.name' => SORT_ASC, 'kb.id' => SORT_ASC])->all();

        $stores = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $stores[] = $this->toStore($row);
            }
        }

        return $stores;
    }

    public function findAvailableBySlug(string $slug): ?AgentStore
    {
        $row = $this->baseSelect()->andWhere(['kb.slug' => $slug])->limit(1)->one();

        return is_array($row) ? $this->toStore($row) : null;
    }

    public function countAvailable(): int
    {
        return (int) $this->predicate(
            $this->connection->createQuery()->from(['kb' => self::TABLE]),
        )->count();
    }

    private function baseSelect(): QueryInterface
    {
        return $this->predicate(
            $this->connection
                ->createQuery()
                ->select([
                    'id' => 'kb.id',
                    'slug' => 'kb.slug',
                    'name' => 'kb.name',
                    'company' => 's.company',
                    'address' => new Expression("JSON_UNQUOTE(JSON_EXTRACT(s.snapshot_json, '$.fields.Address'))"),
                    'city' => new Expression("JSON_UNQUOTE(JSON_EXTRACT(s.snapshot_json, '$.fields.City'))"),
                    'state' => new Expression("JSON_UNQUOTE(JSON_EXTRACT(s.snapshot_json, '$.fields.State'))"),
                    'doc_count' => new Expression(
                        '(SELECT COUNT(*) FROM `documents` `d`'
                        . ' WHERE `d`.`knowledge_base_id` = `kb`.`id`'
                        . " AND `d`.`status` = 'ready' AND `d`.`is_enabled` = 1)",
                    ),
                ])
                ->from(['kb' => self::TABLE])
                ->leftJoin(['s' => self::STORE_TABLE], 's.source_id = kb.source_store_id'),
        );
    }

    private function predicate(QueryInterface $query): QueryInterface
    {
        // The agent realm = the canonical chat-eligibility (source active, KB ready, a usable *indexed* document,
        // and — for Order58 bases — a usable store profile) AND the admin's agent_enabled switch. Sharing
        // {@see KnowledgeBaseChatEligibilitySql} keeps the list, the by-slug 404 gate and the count identical to
        // the per-request ChatAvailabilityPolicy, so an agent can never reach a store the policy would reject.
        return $query
            ->where(['kb.agent_enabled' => 1])
            ->andWhere(new Expression(KnowledgeBaseChatEligibilitySql::isEligible()));
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function toStore(array $row): AgentStore
    {
        return new AgentStore(
            knowledgeBaseId: (int) $row['id'],
            slug: (string) $row['slug'],
            name: (string) $row['name'],
            company: $this->nullableString($row['company'] ?? null),
            address: $this->nullableString($row['address'] ?? null),
            city: $this->nullableString($row['city'] ?? null),
            state: $this->nullableString($row['state'] ?? null),
            documentCount: (int) ($row['doc_count'] ?? 0),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = (string) $value;

        return $string === '' ? null : $string;
    }
}
