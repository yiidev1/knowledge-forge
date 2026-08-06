<?php

declare(strict_types=1);

namespace App\Order58\Client;

use App\Order58\Contract\ActiveFlag;
use App\Order58\Contract\Dto\Order58Account;
use App\Order58\Contract\Dto\Order58Agent;
use App\Order58\Contract\Dto\Order58AuthResult;
use App\Order58\Contract\Dto\Order58Health;
use App\Order58\Contract\Dto\Order58KnowledgeRecord;
use App\Order58\Contract\Dto\Order58Page;
use App\Order58\Contract\Dto\Order58Pagination;
use App\Order58\Contract\Dto\Order58RuleRecord;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

use function is_array;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * Turns a decoded Order58 envelope into typed DTOs. This is the "never trust raw API JSON" boundary:
 * required identifiers are validated (and a missing one raises {@see Order58InvalidResponse}), while any
 * additional optional fields are tolerated and preserved in the record's `raw` array for the deterministic
 * formatters and safe snapshots downstream.
 */
final readonly class Order58ResponseParser
{
    public function __construct(
        private Order58ErrorMapper $errorMapper,
    ) {}

    /**
     * @param array<array-key, mixed> $body
     */
    public function parseHealth(array $body): Order58Health
    {
        $meta = $body['meta'] ?? null;
        $data = $body['data'] ?? null;
        $metaArr = is_array($meta) ? $meta : [];
        $dataArr = is_array($data) ? $data : [];

        return new Order58Health(
            ok: ($body['success'] ?? false) === true,
            apiVersion: $this->nullableStr($metaArr, 'api_version'),
            message: $this->nullableStr($dataArr, 'status') ?? $this->nullableStr($dataArr, 'message'),
        );
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return Order58Page<Order58Account>
     */
    public function parseAccountsPage(array $body): Order58Page
    {
        $items = [];
        foreach ($this->dataList($body) as $record) {
            $items[] = $this->account($record);
        }

        return new Order58Page($items, $this->pagination($body));
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public function parseAccount(array $body): Order58Account
    {
        return $this->account($this->dataObject($body));
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return Order58Page<Order58Agent>
     */
    public function parseAgentsPage(array $body): Order58Page
    {
        $items = [];
        foreach ($this->dataList($body) as $record) {
            $items[] = $this->agent($record);
        }

        return new Order58Page($items, $this->pagination($body));
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return Order58Page<Order58KnowledgeRecord>
     */
    public function parseKnowledgePage(array $body): Order58Page
    {
        $items = [];
        foreach ($this->dataList($body) as $record) {
            $items[] = $this->knowledge($record);
        }

        return new Order58Page($items, $this->pagination($body));
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public function parseKnowledgeRecord(array $body): Order58KnowledgeRecord
    {
        return $this->knowledge($this->dataObject($body));
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return Order58Page<Order58RuleRecord>
     */
    public function parseRulesPage(array $body): Order58Page
    {
        $items = [];
        foreach ($this->dataList($body) as $record) {
            $items[] = $this->rule($record);
        }

        return new Order58Page($items, $this->pagination($body));
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public function parseRule(array $body): Order58RuleRecord
    {
        return $this->rule($this->dataObject($body));
    }

    /**
     * @param array<array-key, mixed> $r
     */
    private function account(array $r): Order58Account
    {
        // account.active is the sole source-active signal. Normalize it explicitly and record whether it was
        // present/valid, so the sync never overwrites a store's status with a guessed "false".
        $active = ActiveFlag::normalize($r['active'] ?? null);

        return new Order58Account(
            id: $this->requireInt($r, 'id'),
            name: $this->str($r, 'name'),
            company: $this->nullableStr($r, 'company'),
            active: $active ?? false,
            activeKnown: $active !== null,
            syncHash: $this->requireStr($r, '_sync_hash'),
            raw: $r,
        );
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public function parseAuthenticate(array $body): Order58AuthResult
    {
        if (($body['success'] ?? null) !== true) {
            return Order58AuthResult::invalid();
        }

        $data = $body['data'] ?? null;
        if (!is_array($data) || ($data['authenticated'] ?? false) !== true) {
            return Order58AuthResult::invalid();
        }

        $user = $data['user'] ?? null;
        if (!is_array($user)) {
            return Order58AuthResult::invalid();
        }

        return Order58AuthResult::success($this->authUser($user));
    }

    /**
     * Builds the safe agent identity from the `/authenticate` user object, which — unlike the list/get
     * agent records — carries no `_sync_hash`.
     *
     * @param array<array-key, mixed> $r
     */
    private function authUser(array $r): Order58Agent
    {
        return new Order58Agent(
            adminId: $this->requireInt($r, 'admin_id'),
            username: $this->str($r, 'username'),
            firstName: $this->nullableStr($r, 'first_name'),
            lastName: $this->nullableStr($r, 'last_name'),
            email: $this->nullableStr($r, 'email_address'),
            contactNumber: $this->nullableStr($r, 'contact_number'),
            role: $this->nullableStr($r, 'role'),
            status: $this->str($r, 'status'),
            userType: $this->str($r, 'user_type'),
            accountId: $this->nullableInt($r, 'account_id'),
            modifiedAt: $this->date($r, 'date_modified'),
            syncHash: '',
            raw: $r,
        );
    }

    /**
     * @param array<array-key, mixed> $r
     */
    private function agent(array $r): Order58Agent
    {
        return new Order58Agent(
            adminId: $this->requireInt($r, 'admin_id'),
            username: $this->str($r, 'username'),
            firstName: $this->nullableStr($r, 'first_name'),
            lastName: $this->nullableStr($r, 'last_name'),
            email: $this->nullableStr($r, 'email_address'),
            contactNumber: $this->nullableStr($r, 'contact_number'),
            role: $this->nullableStr($r, 'role'),
            status: $this->str($r, 'status'),
            userType: $this->str($r, 'user_type'),
            accountId: $this->nullableInt($r, 'account_id'),
            modifiedAt: $this->date($r, 'date_modified'),
            syncHash: $this->requireStr($r, '_sync_hash'),
            raw: $r,
        );
    }

    /**
     * @param array<array-key, mixed> $r
     */
    private function knowledge(array $r): Order58KnowledgeRecord
    {
        return new Order58KnowledgeRecord(
            id: $this->requireInt($r, 'id'),
            storeId: $this->requireInt($r, 'store'),
            title: $this->str($r, 'title'),
            content: $this->str($r, 'description'),
            knowledgeNumber: $this->nullableStr($r, 'knowledge_number'),
            keyword: $this->nullableStr($r, 'knowledge_keyword'),
            type: $this->nullableStr($r, 'type'),
            createdAt: $this->date($r, 'created_at'),
            updatedAt: $this->date($r, 'updated_at'),
            syncHash: $this->requireStr($r, '_sync_hash'),
            raw: $r,
        );
    }

    /**
     * @param array<array-key, mixed> $r
     */
    private function rule(array $r): Order58RuleRecord
    {
        // `store_id` is not part of today's Rules payload; it is read defensively so a future authoritative
        // store id flows straight through without a parser change.
        return new Order58RuleRecord(
            id: $this->requireInt($r, 'id'),
            type: $this->nullableStr($r, 'type'),
            title: $this->str($r, 'title'),
            description: $this->str($r, 'description'),
            ruleKeyword: $this->nullableStr($r, 'rule_keyword'),
            createdName: $this->nullableStr($r, 'created_name'),
            sourceStoreId: $this->nullableInt($r, 'store_id'),
            createdAt: $this->date($r, 'created_at'),
            updatedAt: $this->date($r, 'updated_at'),
            syncHash: $this->requireStr($r, '_sync_hash'),
            raw: $r,
        );
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function dataList(array $body): array
    {
        if (($body['success'] ?? null) !== true) {
            throw $this->errorMapper->invalidResponse('envelope "success" was not true');
        }

        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            throw $this->errorMapper->invalidResponse('envelope "data" was not a list');
        }

        $rows = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                throw $this->errorMapper->invalidResponse('a data row was not an object');
            }
            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function dataObject(array $body): array
    {
        if (($body['success'] ?? null) !== true) {
            throw $this->errorMapper->invalidResponse('envelope "success" was not true');
        }

        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            throw $this->errorMapper->invalidResponse('envelope "data" was not an object');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function pagination(array $body): Order58Pagination
    {
        $paginationRaw = $body['pagination'] ?? null;
        $p = is_array($paginationRaw) ? $paginationRaw : [];

        return new Order58Pagination(
            page: $this->intOr($p, 'page', 1),
            perPage: $this->intOr($p, 'per_page', 0),
            total: $this->intOr($p, 'total', 0),
            totalPages: $this->intOr($p, 'total_pages', 1),
        );
    }

    /**
     * @param array<array-key, mixed> $r
     */
    private function requireInt(array $r, string $key): int
    {
        $value = $r[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value) && (string) (int) $value === $value) {
            return (int) $value;
        }

        throw $this->errorMapper->invalidResponse('missing or non-integer field "' . $key . '"');
    }

    /**
     * @param array<array-key, mixed> $r
     */
    private function requireStr(array $r, string $key): string
    {
        $value = $r[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw $this->errorMapper->invalidResponse('missing or empty field "' . $key . '"');
    }

    /**
     * @param array<array-key, mixed> $r
     */
    private function str(array $r, string $key): string
    {
        $value = $r[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<array-key, mixed> $r
     */
    private function nullableStr(array $r, string $key): ?string
    {
        $value = $r[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        return $value === '' ? null : $value;
    }

    /**
     * @param array<array-key, mixed> $r
     */
    private function nullableInt(array $r, string $key): ?int
    {
        $value = $r[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value) && (string) (int) $value === $value) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $r
     */
    private function intOr(array $r, string $key, int $default): int
    {
        $value = $r[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param array<array-key, mixed> $r
     */
    private function date(array $r, string $key): ?DateTimeImmutable
    {
        $value = $r[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
